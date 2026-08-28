<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Import;

use Cosmotec\EccubeMigration\Api\CouplingProductMapRepositoryInterface;
use Cosmotec\EccubeMigration\Api\CouplingProductRepositoryInterface;
use Cosmotec\EccubeMigration\Api\Data\CouplingProductInterface;
use Cosmotec\EccubeMigration\Api\ItemMapRepositoryInterface;
use Cosmotec\EccubeMigration\Api\ProductMapRepositoryInterface;
use Cosmotec\EccubeMigration\Api\SyncHistoryRepositoryInterface;
use Cosmotec\EccubeMigration\Logger\ImportLogger;
use Cosmotec\EccubeMigration\Model\CouplingProductMap;
use Cosmotec\EccubeMigration\Model\CouplingProductMapFactory;
use Cosmotec\EccubeMigration\Model\ProductLink\ConnectionPartLinkType;
use Cosmotec\EccubeMigration\Model\SyncHistory;
use Magento\Catalog\Api\Data\ProductLinkExtensionFactory;
use Magento\Catalog\Api\Data\ProductLinkInterface;
use Magento\Catalog\Api\Data\ProductLinkInterfaceFactory;
use Magento\Catalog\Api\ProductRepositoryInterface as MagentoProductRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * Imports dtb_coupling_product ("Connection Parts": Item -> specific
 * Product, cross-product-type, SOURCE-CONFIRMED
 * docs/ECCUBE_SPECIFICATION_ARCHITECTURE.md §4) as a distinct relation -
 * deliberately NOT Magento's built-in related/upsell/cross-sell link
 * types (per docs/MIGRATION_ASSUMPTIONS.md §3: "do not blindly map all
 * relationships to Magento 'related'"), and NOT the same mechanism as
 * RelatedProductImporter, which handles the structurally different
 * dtb_related_product (Product -> Product, child-level).
 *
 * Approved and executed since Round 41 (see BUILD_STATUS.md).
 *
 * Follows the exact same shape as ProductReferenceImporter (true 1:N,
 * every source row independently mapped, exposed to the Grouped Product
 * via an extension-attribute plugin rather than a native Magento product
 * link) since Connection Parts has the same "genuine relation table, not
 * a Magento-native concept" character as product references.
 */
class ConnectionPartImporter implements ImporterInterface
{
    public function __construct(
        private readonly CouplingProductRepositoryInterface $couplingProductRepository,
        private readonly CouplingProductMapRepositoryInterface $mapRepository,
        private readonly CouplingProductMapFactory $mapFactory,
        private readonly ItemMapRepositoryInterface $itemMapRepository,
        private readonly ProductMapRepositoryInterface $productMapRepository,
        private readonly SyncHistoryRepositoryInterface $syncHistoryRepository,
        private readonly MagentoProductRepositoryInterface $magentoProductRepository,
        private readonly ProductLinkInterfaceFactory $productLinkFactory,
        private readonly ProductLinkExtensionFactory $productLinkExtensionFactory,
        private readonly ImportLogger $logger
    ) {
    }

    public function import(ImportContext $context): ImportResult
    {
        $result = new ImportResult();
        $batchSize = $context->getBatchSize() ?? 500;
        $offset = 0;

        while (true) {
            $batch = $this->couplingProductRepository->getBatch($offset, $batchSize);

            if ($batch === []) {
                break;
            }

            foreach ($batch as $coupling) {
                $this->importOne($coupling, $context, $result);
            }

            if (count($batch) < $batchSize) {
                break;
            }

            $offset += $batchSize;
        }

        $this->logger->info(sprintf(
            'ConnectionPartImporter run %s complete: imported=%d updated=%d skipped=%d errors=%d',
            $context->getRunId(),
            $result->getImported(),
            $result->getUpdated(),
            $result->getSkipped(),
            $result->getErrors()
        ));

        return $result;
    }

    private function importOne(CouplingProductInterface $coupling, ImportContext $context, ImportResult $result): void
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);

        try {
            $itemMap = $this->itemMapRepository->getByEccubeItemId($coupling->getItemId());
            $parentMagentoId = $this->toIntOrNull($itemMap?->getMagentoProductId());

            $productMap = $this->productMapRepository->getByEccubeProductId($coupling->getProductId());
            $connectedMagentoId = $this->toIntOrNull($productMap?->getMagentoProductId());

            if ($parentMagentoId === null || $connectedMagentoId === null) {
                // Either side not imported yet - retried automatically.
                $result->incrementSkipped();

                if (!$context->isDryRun()) {
                    $this->saveMap($coupling, $parentMagentoId, $connectedMagentoId, CouplingProductMap::STATUS_PENDING, 'Parent Item or connected Product not imported yet');
                }

                return;
            }

            $existing = $this->mapRepository->getByCouplingId($coupling->getId());

            if ($existing !== null && $existing->getMagentoConnectedProductId() !== null) {
                // Static existence link - nothing to change once both
                // sides are resolved.
                $result->incrementSkipped();

                return;
            }

            if ($context->isDryRun()) {
                $result->incrementImported();
                $this->logger->info(sprintf(
                    '[DRY RUN] Would connect Grouped Product id=%d to Simple Product id=%d (coupling id=%d)',
                    $parentMagentoId,
                    $connectedMagentoId,
                    $coupling->getId()
                ));

                return;
            }

            $isUpdate = $existing !== null;
            $this->saveMap(
                $coupling,
                $parentMagentoId,
                $connectedMagentoId,
                $isUpdate ? CouplingProductMap::STATUS_UPDATED : CouplingProductMap::STATUS_IMPORTED,
                null
            );

            if ($isUpdate) {
                $result->incrementUpdated();
            } else {
                $result->incrementImported();
            }

            $this->syncProductLink($parentMagentoId, $connectedMagentoId);

            $this->recordHistory(
                $context,
                $coupling->getId(),
                $connectedMagentoId,
                $isUpdate ? SyncHistory::STATUS_UPDATED : SyncHistory::STATUS_IMPORTED,
                null,
                $startTime,
                $startMemory
            );
        } catch (LocalizedException | \Throwable $e) {
            $result->incrementErrors();
            $this->logger->error(sprintf('Coupling product %d failed: %s', $coupling->getId(), $e->getMessage()));

            if (!$context->isDryRun()) {
                $this->saveMap($coupling, null, null, CouplingProductMap::STATUS_ERROR, $e->getMessage());
            }

            $this->recordHistory($context, $coupling->getId(), null, SyncHistory::STATUS_ERROR, $e->getMessage(), $startTime, $startMemory);
        }
    }

    /**
     * Marks mappings obsolete when their source coupling row no longer
     * exists for a given item - each is independent, matching
     * ProductReferenceImporter::markObsoleteForProduct().
     */
    public function markObsoleteForItem(int $eccubeItemId): int
    {
        $sourceIds = array_map(
            static fn (CouplingProductInterface $c): int => $c->getId(),
            $this->couplingProductRepository->getByItemId($eccubeItemId)
        );

        $obsolete = 0;

        foreach ($this->mapRepository->getByItemId($eccubeItemId) as $map) {
            // See RelatedProductImporter::markObsoleteForProduct() - same
            // raw-DB-string-vs-int strict comparison bug.
            if (in_array((int) $map->getEccubeCouplingId(), $sourceIds, true)) {
                continue;
            }

            if ($map->getStatus() === CouplingProductMap::STATUS_OBSOLETE) {
                continue;
            }

            $map->setStatus(CouplingProductMap::STATUS_OBSOLETE);
            $map->setErrorMessage('Source coupling row no longer exists in dtb_coupling_product');
            $this->mapRepository->save($map);
            $obsolete++;
        }

        return $obsolete;
    }

    private function saveMap(
        CouplingProductInterface $coupling,
        ?int $parentMagentoId,
        ?int $connectedMagentoId,
        string $status,
        ?string $error
    ): void {
        $existing = $this->mapRepository->getByCouplingId($coupling->getId());
        /** @var CouplingProductMap $map */
        $map = $existing ?? $this->mapFactory->create();
        $map->setEccubeCouplingId($coupling->getId());
        $map->setEccubeItemId($coupling->getItemId());
        $map->setEccubeProductId($coupling->getProductId());
        $map->setMagentoParentProductId($parentMagentoId);
        $map->setMagentoConnectedProductId($connectedMagentoId);
        // ORDERING FALLBACK: dtb_coupling_product has no sort_no column
        // (verified live) - source id order is used, same pattern as
        // ProductReferenceMap/RelatedProductMap.
        $map->setSortNo($coupling->getId());
        $map->setStatus($status);
        $map->setErrorMessage($error);

        if ($error === null) {
            $map->setLastSyncedAt((new \DateTimeImmutable())->format('Y-m-d H:i:s'));
        }

        $this->mapRepository->save($map);
    }

    /**
     * Task 2 QA rework: mirrors ProductRelationImporter's associated-link
     * pattern, but for the "connection_part" link type instead of
     * "associated" - keeps the native Magento product-link representation
     * (the one the admin "EC-CUBE Connection Parts" fieldset now reads
     * and writes, see Ui\DataProvider\Product\Form\Modifier\
     * ConnectionParts) in sync with every EC-CUBE-driven import/sync run,
     * on top of - not instead of - the existing eccube_coupling_product_map
     * provenance tracking. Idempotent: a link already present for this
     * SKU pair is left untouched.
     */
    private function syncProductLink(int $parentMagentoId, int $connectedMagentoId): void
    {
        try {
            $parentProduct = $this->magentoProductRepository->getById($parentMagentoId);
            $connectedProduct = $this->magentoProductRepository->getById($connectedMagentoId);
        } catch (LocalizedException) {
            return;
        }

        $existingLinks = $parentProduct->getProductLinks() ?? [];

        foreach ($existingLinks as $link) {
            if ($link->getLinkType() === ConnectionPartLinkType::LINK_TYPE_CODE
                && $link->getLinkedProductSku() === $connectedProduct->getSku()) {
                return;
            }
        }

        $position = count(array_filter(
            $existingLinks,
            static fn (ProductLinkInterface $link): bool => $link->getLinkType() === ConnectionPartLinkType::LINK_TYPE_CODE
        ));

        $link = $this->productLinkFactory->create();
        $link->setSku($parentProduct->getSku());
        $link->setLinkedProductSku($connectedProduct->getSku());
        $link->setLinkType(ConnectionPartLinkType::LINK_TYPE_CODE);
        $link->setPosition($position + 1);

        $extensionAttributes = $link->getExtensionAttributes() ?? $this->productLinkExtensionFactory->create();
        $link->setExtensionAttributes($extensionAttributes);

        $existingLinks[] = $link;
        $parentProduct->setProductLinks($existingLinks);

        try {
            $this->magentoProductRepository->save($parentProduct);
        } catch (LocalizedException $e) {
            $this->logger->error(sprintf(
                'ConnectionPartImporter: failed to save native product link parent=%d connected=%d: %s',
                $parentMagentoId,
                $connectedMagentoId,
                $e->getMessage()
            ));
        }
    }

    private function toIntOrNull(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    private function recordHistory(
        ImportContext $context,
        int $sourceId,
        ?int $targetId,
        string $status,
        ?string $message,
        float $startTime,
        int $startMemory
    ): void {
        if ($context->isDryRun()) {
            return;
        }

        $this->syncHistoryRepository->record(
            $context->getRunId(),
            SyncHistory::ENTITY_TYPE_COUPLING_PRODUCT,
            SyncHistory::OPERATION_IMPORT,
            $sourceId,
            $targetId,
            $status,
            $message,
            (int) round((microtime(true) - $startTime) * 1000),
            max(0, memory_get_usage(true) - $startMemory)
        );
    }
}
