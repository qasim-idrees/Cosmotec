<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Import;

use Cosmotec\EccubeMigration\Api\Data\RelatedProductInterface;
use Cosmotec\EccubeMigration\Api\ProductMapRepositoryInterface;
use Cosmotec\EccubeMigration\Api\RelatedProductMapRepositoryInterface;
use Cosmotec\EccubeMigration\Api\RelatedProductRepositoryInterface;
use Cosmotec\EccubeMigration\Api\SyncHistoryRepositoryInterface;
use Cosmotec\EccubeMigration\Logger\ImportLogger;
use Cosmotec\EccubeMigration\Model\RelatedProductMap;
use Cosmotec\EccubeMigration\Model\RelatedProductMapFactory;
use Cosmotec\EccubeMigration\Model\SyncHistory;
use Magento\Catalog\Api\Data\ProductLinkInterface;
use Magento\Catalog\Api\Data\ProductLinkInterfaceFactory;
use Magento\Catalog\Api\ProductRepositoryInterface as MagentoProductRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Imports dtb_related_product (Product -> Product, child-level,
 * SOURCE-CONFIRMED docs/SPECIFICATION_MAGENTO_DATA_MODEL.md §8) as
 * Magento's NATIVE related-product links - this relationship genuinely
 * matches what Magento's built-in "Related Products" feature represents,
 * unlike Connection Parts (see ConnectionPartImporter), which must NOT use
 * this same mechanism.
 *
 * NOT YET APPROVED FOR EXECUTION - see BUILD_STATUS.md.
 *
 * Batched per source product: every related link for one product is
 * collected and written in a single ProductRepository::save() call,
 * mirroring the existing ProductRelationImporter exactly.
 */
class RelatedProductImporter implements ImporterInterface
{
    private const LINK_TYPE_RELATED = 'related';

    public function __construct(
        private readonly RelatedProductRepositoryInterface $relatedProductRepository,
        private readonly RelatedProductMapRepositoryInterface $mapRepository,
        private readonly RelatedProductMapFactory $mapFactory,
        private readonly ProductMapRepositoryInterface $productMapRepository,
        private readonly SyncHistoryRepositoryInterface $syncHistoryRepository,
        private readonly MagentoProductRepositoryInterface $magentoProductRepository,
        private readonly ProductLinkInterfaceFactory $productLinkFactory,
        private readonly ImportLogger $logger
    ) {
    }

    public function import(ImportContext $context): ImportResult
    {
        $result = new ImportResult();
        $batchSize = $context->getBatchSize() ?? 200;
        $offset = 0;

        while (true) {
            $batch = $this->relatedProductRepository->getBatch($offset, $batchSize);

            if ($batch === []) {
                break;
            }

            $grouped = [];

            foreach ($batch as $relation) {
                $grouped[$relation->getProductId()][] = $relation;
            }

            foreach ($grouped as $eccubeProductId => $relations) {
                $this->linkProductRelations((int) $eccubeProductId, $relations, $context, $result);
            }

            if (count($batch) < $batchSize) {
                break;
            }

            $offset += $batchSize;
        }

        $this->logger->info(sprintf(
            'RelatedProductImporter run %s complete: linked=%d skipped=%d errors=%d',
            $context->getRunId(),
            $result->getImported(),
            $result->getSkipped(),
            $result->getErrors()
        ));

        return $result;
    }

    /**
     * @param RelatedProductInterface[] $relations
     */
    private function linkProductRelations(int $eccubeProductId, array $relations, ImportContext $context, ImportResult $result): void
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);

        try {
            $this->doLinkProductRelations($eccubeProductId, $relations, $context, $result, $startTime, $startMemory);
        } catch (LocalizedException | \Throwable $e) {
            // Top-level safety net: a bad source row or an infrastructure
            // failure (e.g. a mapping table not yet migrated) must not
            // abort the whole run - every other product's relations still
            // need to be attempted. See CategoryImporter et al. for the
            // same pattern; this class previously lacked it, letting one
            // failure crash the entire command instead of being recorded
            // per-row and retried.
            $this->logger->error(sprintf('EC-CUBE product id=%d: related products failed: %s', $eccubeProductId, $e->getMessage()));
            $result->incrementErrors(count($relations));

            foreach ($relations as $relation) {
                if (!$context->isDryRun()) {
                    // Best-effort only: if the failure itself means the map
                    // table can't be reached (e.g. not migrated yet),
                    // recording the error there would throw again. The
                    // error is already logged and counted above either way
                    // - never let recording the failure become a second,
                    // unhandled failure.
                    try {
                        $this->saveMap($relation, null, null, RelatedProductMap::STATUS_ERROR, $e->getMessage());
                    } catch (\Throwable) {
                        // Swallowed deliberately - see comment above.
                    }
                }

                $this->recordHistory($context, $relation->getId(), null, SyncHistory::STATUS_ERROR, $e->getMessage(), $startTime, $startMemory);
            }
        }
    }

    /**
     * @param RelatedProductInterface[] $relations
     */
    private function doLinkProductRelations(
        int $eccubeProductId,
        array $relations,
        ImportContext $context,
        ImportResult $result,
        float $startTime,
        int $startMemory
    ): void {
        $sourceMap = $this->productMapRepository->getByEccubeProductId($eccubeProductId);
        $sourceMagentoId = $this->toIntOrNull($sourceMap?->getMagentoProductId());

        if ($sourceMap === null || $sourceMagentoId === null) {
            $result->incrementSkipped(count($relations));

            return;
        }

        // Resolve every target product up front so a product not yet
        // imported never blocks the ones that are ready - each row is
        // independently retried on the next run via its own map status.
        $pending = [];

        foreach ($relations as $relation) {
            $existing = $this->mapRepository->getByRelatedId($relation->getId());

            if ($existing !== null && $existing->getMagentoRelatedProductId() !== null) {
                // Already linked and the relation is a static (id-only)
                // fact with nothing else to change - skip.
                $result->incrementSkipped();

                continue;
            }

            $targetMap = $this->productMapRepository->getByEccubeProductId($relation->getRelatedProductId());
            $targetMagentoId = $this->toIntOrNull($targetMap?->getMagentoProductId());

            if ($targetMagentoId === null) {
                $result->incrementSkipped();

                if (!$context->isDryRun()) {
                    $this->saveMap($relation, $sourceMagentoId, null, RelatedProductMap::STATUS_PENDING, 'Target related product not imported yet');
                }

                continue;
            }

            $pending[] = [
                'relation' => $relation,
                'targetMagentoId' => $targetMagentoId,
                // Reuse the SKU already stored on eccube_product_map
                // rather than a second ProductRepository round trip per
                // target - it was captured at import time and is
                // authoritative for an already-imported product.
                'targetSku' => (string) $targetMap->getSku(),
            ];
        }

        if ($pending === []) {
            return;
        }

        if ($context->isDryRun()) {
            $result->incrementImported(count($pending));
            $this->logger->info(sprintf(
                '[DRY RUN] Would link %d related product(s) to Simple Product id=%d (EC-CUBE product id=%d)',
                count($pending),
                $sourceMagentoId,
                $eccubeProductId
            ));

            return;
        }

        try {
            $product = $this->magentoProductRepository->getById($sourceMagentoId, true, 0);
        } catch (NoSuchEntityException $e) {
            $this->logger->error(sprintf('EC-CUBE product id=%d: mapped Magento product id=%d no longer exists: %s', $eccubeProductId, $sourceMagentoId, $e->getMessage()));
            $result->incrementErrors(count($pending));

            return;
        }

        $existingLinks = $product->getProductLinks() ?? [];
        $existingRelatedSkus = array_map(
            static fn (ProductLinkInterface $link): string => $link->getLinkedProductSku(),
            array_filter($existingLinks, static fn (ProductLinkInterface $link): bool => $link->getLinkType() === self::LINK_TYPE_RELATED)
        );

        $newLinks = $existingLinks;
        $position = count($existingRelatedSkus);

        foreach ($pending as $entry) {
            $targetSku = $entry['targetSku'];

            if ($targetSku === '' || in_array($targetSku, $existingRelatedSkus, true)) {
                continue;
            }

            $position++;
            $link = $this->productLinkFactory->create();
            $link->setSku($product->getSku());
            $link->setLinkedProductSku($targetSku);
            $link->setLinkType(self::LINK_TYPE_RELATED);
            $link->setPosition($position);
            $newLinks[] = $link;
        }

        try {
            $product->setData('store_id', 0);
            $product->setProductLinks($newLinks);
            $this->magentoProductRepository->save($product);
        } catch (LocalizedException | \Throwable $e) {
            $this->logger->error(sprintf('EC-CUBE product id=%d: failed to save related links: %s', $eccubeProductId, $e->getMessage()));
            $result->incrementErrors(count($pending));

            foreach ($pending as $entry) {
                $this->saveMap($entry['relation'], $sourceMagentoId, null, RelatedProductMap::STATUS_ERROR, $e->getMessage());
            }

            return;
        }

        foreach ($pending as $entry) {
            /** @var RelatedProductInterface $relation */
            $relation = $entry['relation'];
            $this->saveMap($relation, $sourceMagentoId, $entry['targetMagentoId'], RelatedProductMap::STATUS_IMPORTED, null);
            $result->incrementImported();

            $this->recordHistory($context, $relation->getId(), $entry['targetMagentoId'], SyncHistory::STATUS_IMPORTED, null, $startTime, $startMemory);
        }
    }

    /**
     * Marks mappings obsolete when their source relation row no longer
     * exists for a given product - each is independent, matching
     * ProductReferenceImporter::markObsoleteForProduct() and
     * ConnectionPartImporter::markObsoleteForItem().
     */
    public function markObsoleteForProduct(int $eccubeProductId): int
    {
        $sourceIds = array_map(
            static fn (RelatedProductInterface $r): int => $r->getId(),
            $this->relatedProductRepository->getByProductId($eccubeProductId)
        );

        $obsolete = 0;

        foreach ($this->mapRepository->getByProductId($eccubeProductId) as $map) {
            if (in_array($map->getEccubeRelatedId(), $sourceIds, true)) {
                continue;
            }

            if ($map->getStatus() === RelatedProductMap::STATUS_OBSOLETE) {
                continue;
            }

            $map->setStatus(RelatedProductMap::STATUS_OBSOLETE);
            $map->setErrorMessage('Source relation no longer exists in dtb_related_product');
            $this->mapRepository->save($map);
            $obsolete++;
        }

        return $obsolete;
    }

    private function saveMap(
        RelatedProductInterface $relation,
        ?int $magentoProductId,
        ?int $magentoRelatedProductId,
        string $status,
        ?string $error
    ): void {
        $existing = $this->mapRepository->getByRelatedId($relation->getId());
        /** @var RelatedProductMap $map */
        $map = $existing ?? $this->mapFactory->create();
        $map->setEccubeRelatedId($relation->getId());
        $map->setEccubeProductId($relation->getProductId());
        $map->setEccubeRelatedProductId($relation->getRelatedProductId());
        $map->setMagentoProductId($magentoProductId);
        $map->setMagentoRelatedProductId($magentoRelatedProductId);
        // ORDERING FALLBACK: dtb_related_product has no sort_no column
        // (verified live) - source id order is used, same pattern as
        // ProductReferenceMap/CouplingProductMap.
        $map->setSortNo($relation->getId());
        $map->setStatus($status);
        $map->setErrorMessage($error);

        if ($error === null) {
            $map->setLastSyncedAt((new \DateTimeImmutable())->format('Y-m-d H:i:s'));
        }

        $this->mapRepository->save($map);
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
            SyncHistory::ENTITY_TYPE_RELATED_PRODUCT,
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
