<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Import;

use Cosmotec\EccubeMigration\Api\AttributeSetMapRepositoryInterface;
use Cosmotec\EccubeMigration\Api\ProductMapRepositoryInterface;
use Cosmotec\EccubeMigration\Api\SyncHistoryRepositoryInterface;
use Cosmotec\EccubeMigration\Logger\ImportLogger;
use Cosmotec\EccubeMigration\Model\Mapper\AttributeSetResolver;
use Cosmotec\EccubeMigration\Model\ProductMap;
use Cosmotec\EccubeMigration\Model\SyncHistory;
use Magento\Catalog\Api\ProductRepositoryInterface as MagentoProductRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Reassigns each already-imported Simple Product to the Magento attribute
 * set resolved from its OWNING ITEM's EC-CUBE category tree - EC-CUBE
 * assigns categories at the item (parent) level via dtb_category_item,
 * never per dtb_product child, so a Simple Product's target set is always
 * resolved through ProductMap::getEccubeItemId(), matching how the Round 32
 * report computed per-set product counts (products inherit their parent
 * item's category resolution).
 *
 * Iterates ONLY eccube_product_map rows (ProductMapRepository::getMappedBatch)
 * - see ItemAttributeSetAssignmentImporter for why this scoping is a hard
 * safety boundary, not an implementation detail.
 */
class ProductAttributeSetAssignmentImporter implements ImporterInterface
{
    public function __construct(
        private readonly ProductMapRepositoryInterface $productMapRepository,
        private readonly AttributeSetResolver $attributeSetResolver,
        private readonly AttributeSetMapRepositoryInterface $attributeSetMapRepository,
        private readonly SyncHistoryRepositoryInterface $syncHistoryRepository,
        private readonly MagentoProductRepositoryInterface $magentoProductRepository,
        private readonly ImportLogger $logger
    ) {
    }

    public function import(ImportContext $context): ImportResult
    {
        return $this->importLimited($context, null);
    }

    /**
     * @param int|null $limit stop after this many rows examined - for a
     *                        small controlled real-data test before the
     *                        full batch (Step 8).
     */
    public function importLimited(ImportContext $context, ?int $limit): ImportResult
    {
        $result = new ImportResult();
        $batchSize = $context->getBatchSize() ?? 200;
        $offset = 0;
        $processed = 0;

        while (true) {
            $batch = $this->productMapRepository->getMappedBatch($offset, $batchSize);

            if ($batch === []) {
                break;
            }

            foreach ($batch as $productMap) {
                $this->assignOne($productMap, $context, $result);
                $processed++;

                if ($limit !== null && $processed >= $limit) {
                    break 2;
                }
            }

            if (count($batch) < $batchSize) {
                break;
            }

            $offset += $batchSize;
        }

        $this->logger->info(sprintf(
            'ProductAttributeSetAssignmentImporter run %s complete: assigned=%d updated=%d skipped=%d errors=%d needsReview=%d',
            $context->getRunId(),
            $result->getImported(),
            $result->getUpdated(),
            $result->getSkipped(),
            $result->getErrors(),
            $result->getNeedsReview()
        ));

        return $result;
    }

    private function assignOne(ProductMap $productMap, ImportContext $context, ImportResult $result): void
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);
        $eccubeProductId = (int) $productMap->getEccubeProductId();
        $magentoProductId = (int) $productMap->getMagentoProductId();
        $eccubeItemId = $productMap->getEccubeItemId() !== null ? (int) $productMap->getEccubeItemId() : null;

        try {
            if ($eccubeItemId === null) {
                $result->incrementNeedsReview();
                $this->recordHistory(
                    $context,
                    $eccubeProductId,
                    $magentoProductId,
                    SyncHistory::STATUS_SKIPPED,
                    'Product has no known owning EC-CUBE item; cannot resolve a category-derived attribute set.',
                    $startTime,
                    $startMemory
                );

                return;
            }

            // See ItemAttributeSetAssignmentImporter::assignOne() - an
            // owning item with no category chain routes to the dedicated
            // "Uncategorized" set (Round 45 decision) rather than being
            // left unresolved.
            $topLevelCategoryId = $this->attributeSetResolver->resolveTopLevelCategoryId($eccubeItemId)
                ?? AttributeSetResolver::UNCATEGORIZED_TOP_LEVEL_ID;

            $setMap = $this->attributeSetMapRepository->getByTopLevelCategoryId($topLevelCategoryId);
            $rawTargetSetId = $setMap?->getMagentoAttributeSetId();

            if ($rawTargetSetId === null) {
                $result->incrementErrors();
                $message = sprintf('Resolved top-level category %d has no imported Magento attribute set yet.', $topLevelCategoryId);
                $this->logger->error(sprintf('Product %d attribute-set assignment failed: %s', $eccubeProductId, $message));
                $this->recordHistory($context, $eccubeProductId, $magentoProductId, SyncHistory::STATUS_ERROR, $message, $startTime, $startMemory);

                return;
            }

            // See ItemAttributeSetAssignmentImporter::assignOne() for why
            // this cast is required before any strict comparison.
            $targetSetId = (int) $rawTargetSetId;

            try {
                $product = $this->magentoProductRepository->getById($magentoProductId, true, 0);
            } catch (NoSuchEntityException $e) {
                $result->incrementErrors();
                $message = sprintf('Magento product %d no longer exists: %s', $magentoProductId, $e->getMessage());
                $this->logger->error(sprintf('Product %d attribute-set assignment failed: %s', $eccubeProductId, $message));
                $this->recordHistory($context, $eccubeProductId, $magentoProductId, SyncHistory::STATUS_ERROR, $message, $startTime, $startMemory);

                return;
            }

            $currentSetId = (int) $product->getAttributeSetId();

            if ($currentSetId === $targetSetId) {
                $result->incrementSkipped();

                return;
            }

            if ($context->isDryRun()) {
                $result->incrementImported();
                $this->logger->info(sprintf(
                    '[DRY RUN] Would reassign Simple Product id=%d (EC-CUBE product id=%d) from attribute_set_id=%d to %d',
                    $magentoProductId,
                    $eccubeProductId,
                    $currentSetId,
                    $targetSetId
                ));

                return;
            }

            $isUpdate = $productMap->getSpecificationValueHash() !== null;

            $product->setAttributeSetId($targetSetId);
            $product->setData('store_id', 0);
            $this->magentoProductRepository->save($product);

            $reloaded = $this->magentoProductRepository->getById($magentoProductId, false, 0, true);
            $actualSetId = (int) $reloaded->getAttributeSetId();

            if ($actualSetId !== $targetSetId) {
                $result->incrementErrors();
                $message = sprintf('Verification failed: expected attribute_set_id=%d after save, actual=%d.', $targetSetId, $actualSetId);
                $this->logger->error(sprintf('Product %d attribute-set assignment failed verification: %s', $eccubeProductId, $message));
                $this->recordHistory($context, $eccubeProductId, $magentoProductId, SyncHistory::STATUS_ERROR, $message, $startTime, $startMemory);

                return;
            }

            if ($isUpdate) {
                $result->incrementUpdated();
            } else {
                $result->incrementImported();
            }

            $this->recordHistory(
                $context,
                $eccubeProductId,
                $magentoProductId,
                SyncHistory::STATUS_UPDATED,
                sprintf('Reassigned attribute_set_id %d -> %d', $currentSetId, $targetSetId),
                $startTime,
                $startMemory
            );
        } catch (\Throwable $e) {
            $result->incrementErrors();
            $this->logger->error(sprintf('Product %d attribute-set assignment failed: %s', $eccubeProductId, $e->getMessage()));
            $this->recordHistory($context, $eccubeProductId, $magentoProductId, SyncHistory::STATUS_ERROR, $e->getMessage(), $startTime, $startMemory);
        }
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
            SyncHistory::ENTITY_TYPE_PRODUCT,
            SyncHistory::OPERATION_ASSIGN_SET,
            $sourceId,
            $targetId,
            $status,
            $message,
            (int) round((microtime(true) - $startTime) * 1000),
            max(0, memory_get_usage(true) - $startMemory)
        );
    }
}
