<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Import;

use Cosmotec\EccubeMigration\Api\Data\ProductReferenceInterface;
use Cosmotec\EccubeMigration\Api\ProductMapRepositoryInterface;
use Cosmotec\EccubeMigration\Api\ProductReferenceMapRepositoryInterface;
use Cosmotec\EccubeMigration\Api\ProductReferenceRepositoryInterface;
use Cosmotec\EccubeMigration\Api\SyncHistoryRepositoryInterface;
use Cosmotec\EccubeMigration\Logger\ImportLogger;
use Cosmotec\EccubeMigration\Model\ProductReferenceMap;
use Cosmotec\EccubeMigration\Model\ProductReferenceMapFactory;
use Cosmotec\EccubeMigration\Model\SyncHistory;
use Magento\Framework\Exception\LocalizedException;

/**
 * Imports dtb_product_reference as a true 1:N relation.
 *
 * Every source reference is preserved and independently mapped. The admin
 * UI shows only two slots, but that is a form constraint - flattening to
 * document_name_1/2 + reference_link_1/2 would silently truncate any
 * product carrying more, so the mapping table is the canonical store and
 * the frontend reads it through the mapping layer.
 */
class ProductReferenceImporter implements ImporterInterface
{
    public function __construct(
        private readonly ProductReferenceRepositoryInterface $referenceRepository,
        private readonly ProductReferenceMapRepositoryInterface $mapRepository,
        private readonly ProductReferenceMapFactory $mapFactory,
        private readonly ProductMapRepositoryInterface $productMapRepository,
        private readonly SyncHistoryRepositoryInterface $syncHistoryRepository,
        private readonly ImportLogger $logger
    ) {
    }

    public function import(ImportContext $context): ImportResult
    {
        return $this->importFiltered($context, null, null);
    }

    public function importFiltered(ImportContext $context, ?int $limit, ?int $sourceProductId): ImportResult
    {
        $result = new ImportResult();
        $batchSize = $context->getBatchSize() ?? 500;
        $offset = 0;
        $processed = 0;

        while (true) {
            $batch = $sourceProductId !== null
                ? $this->referenceRepository->getByProductId($sourceProductId)
                : $this->referenceRepository->getBatch($offset, $batchSize);

            if ($batch === []) {
                break;
            }

            foreach ($batch as $reference) {
                $this->importOne($reference, $context, $result);
                $processed++;

                if ($limit !== null && $processed >= $limit) {
                    break 2;
                }
            }

            if ($sourceProductId !== null || count($batch) < $batchSize) {
                break;
            }

            $offset += $batchSize;
        }

        $this->logger->info(sprintf(
            'ProductReferenceImporter run %s complete: imported=%d updated=%d skipped=%d errors=%d',
            $context->getRunId(),
            $result->getImported(),
            $result->getUpdated(),
            $result->getSkipped(),
            $result->getErrors()
        ));

        return $result;
    }

    private function importOne(ProductReferenceInterface $reference, ImportContext $context, ImportResult $result): void
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);

        try {
            $productMap = $this->productMapRepository->getByEccubeProductId($reference->getProductId());
            $rawProductId = $productMap?->getMagentoProductId();
            // Magento AbstractModel getters return strings; cast for typed params.
            $magentoProductId = $rawProductId !== null && $rawProductId !== '' ? (int) $rawProductId : null;

            if ($magentoProductId === null) {
                // Product not imported yet: leave pending so the next run
                // retries rather than dropping the reference.
                $result->incrementSkipped();

                if (!$context->isDryRun()) {
                    $this->saveMap($reference, null, ProductReferenceMap::STATUS_PENDING, 'Product not imported yet');
                }

                return;
            }

            $existing = $this->mapRepository->getByReferenceId($reference->getId());

            if ($existing !== null
                && $existing->getContentHash() === $reference->getContentHash()
                && in_array($existing->getStatus(), [ProductReferenceMap::STATUS_IMPORTED, ProductReferenceMap::STATUS_UPDATED], true)) {
                $result->incrementSkipped();

                return;
            }

            if ($context->isDryRun()) {
                $result->incrementImported();
                $this->logger->info(sprintf(
                    '[DRY RUN] Would map reference %d ("%s") to Magento product %d',
                    $reference->getId(),
                    (string) $reference->getName(),
                    $magentoProductId
                ));

                return;
            }

            $isUpdate = $existing !== null && $existing->getMagentoProductId() !== null;
            $this->saveMap(
                $reference,
                $magentoProductId,
                $isUpdate ? ProductReferenceMap::STATUS_UPDATED : ProductReferenceMap::STATUS_IMPORTED,
                null
            );

            if ($isUpdate) {
                $result->incrementUpdated();
            } else {
                $result->incrementImported();
            }

            $this->recordHistory(
                $context,
                $reference->getId(),
                $magentoProductId,
                $isUpdate ? SyncHistory::STATUS_UPDATED : SyncHistory::STATUS_IMPORTED,
                null,
                $startTime,
                $startMemory
            );
        } catch (LocalizedException | \Throwable $e) {
            $result->incrementErrors();
            $this->logger->error(sprintf('Product reference %d failed: %s', $reference->getId(), $e->getMessage()));

            if (!$context->isDryRun()) {
                $this->saveMap($reference, null, ProductReferenceMap::STATUS_ERROR, $e->getMessage());
            }

            $this->recordHistory($context, $reference->getId(), null, SyncHistory::STATUS_ERROR, $e->getMessage(), $startTime, $startMemory);
        }
    }

    private function saveMap(
        ProductReferenceInterface $reference,
        ?int $magentoProductId,
        string $status,
        ?string $error
    ): void {
        $existing = $this->mapRepository->getByReferenceId($reference->getId());
        /** @var ProductReferenceMap $map */
        $map = $existing ?? $this->mapFactory->create();
        $map->setEccubeReferenceId($reference->getId());
        $map->setEccubeProductId($reference->getProductId());
        $map->setMagentoProductId($magentoProductId);
        $map->setReferenceName($reference->getName());
        $map->setReferenceLink($reference->getLink());
        // ORDERING FALLBACK (verified against the production schema):
        // dtb_product_reference has no sort_no or equivalent ordering
        // column - its columns are id, product_id, creator_id, name, link,
        // create_date, update_date, discriminator_type. Source id order is
        // therefore used as a deterministic, stable fallback. If EC-CUBE
        // ever gains an explicit ordering column, this is the single place
        // to change.
        $map->setSortNo($reference->getId());
        $map->setContentHash($reference->getContentHash());
        $map->setStatus($status);
        $map->setErrorMessage($error);

        if ($error === null) {
            $map->setLastSyncedAt((new \DateTimeImmutable())->format('Y-m-d H:i:s'));
        }

        $this->mapRepository->save($map);
    }

    /**
     * Marks mappings obsolete when their source reference no longer
     * exists. Each reference is independent: removing one must never
     * disturb the others belonging to the same product.
     */
    public function markObsoleteForProduct(int $eccubeProductId): int
    {
        $sourceIds = array_map(
            static fn (ProductReferenceInterface $r): int => $r->getId(),
            $this->referenceRepository->getByProductId($eccubeProductId)
        );

        $obsolete = 0;

        foreach ($this->mapRepository->getByProductId($eccubeProductId) as $map) {
            if (in_array($map->getEccubeReferenceId(), $sourceIds, true)) {
                continue;
            }

            if ($map->getStatus() === ProductReferenceMap::STATUS_OBSOLETE) {
                continue;
            }

            $map->setStatus(ProductReferenceMap::STATUS_OBSOLETE);
            $map->setErrorMessage('Source reference no longer exists in dtb_product_reference');
            $this->mapRepository->save($map);
            $obsolete++;
        }

        return $obsolete;
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
            SyncHistory::ENTITY_TYPE_PRODUCT_REFERENCE,
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
