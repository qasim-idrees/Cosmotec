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
use Cosmotec\EccubeMigration\Api\ItemMapRepositoryInterface;
use Cosmotec\EccubeMigration\Api\SyncHistoryRepositoryInterface;
use Cosmotec\EccubeMigration\Logger\ImportLogger;
use Cosmotec\EccubeMigration\Model\ItemMap;
use Cosmotec\EccubeMigration\Model\Mapper\AttributeSetResolver;
use Cosmotec\EccubeMigration\Model\SyncHistory;
use Magento\Catalog\Api\ProductRepositoryInterface as MagentoProductRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Reassigns each already-imported Grouped Product to the Magento attribute
 * set resolved from its EC-CUBE category tree (AttributeSetResolver), so
 * ItemAttributeValueImporter can subsequently write real EAV values.
 *
 * Iterates ONLY eccube_item_map rows (ItemMapRepository::getMappedBatch) -
 * this is a hard safety boundary, not an implementation detail: it is what
 * guarantees the importer can never reassign a product outside migration
 * scope, in particular the 84 pre-existing "ct_*"/Coaxial-set products
 * confirmed (Round 33) to have zero eccube_item_map/eccube_product_map
 * rows. Nothing in this class ever queries catalog_product_entity directly
 * for candidates.
 *
 * Idempotent by direct comparison against the product's live
 * attribute_set_id (no extra hash/column needed): if the resolved target
 * already matches, the row is skipped. Reassignment is destructive for any
 * EAV value outside the target set's attribute list (confirmed empirically
 * in the Round 33 controlled experiment - Magento's EntityManager deletes
 * those rows on save, it does not hide them) - safe here specifically
 * because every eccube_item_map-mapped product currently carries zero
 * eccube_spec_* or ct_* data (verified before this importer was written).
 */
class ItemAttributeSetAssignmentImporter implements ImporterInterface
{
    public function __construct(
        private readonly ItemMapRepositoryInterface $itemMapRepository,
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
            $batch = $this->itemMapRepository->getMappedBatch($offset, $batchSize);

            if ($batch === []) {
                break;
            }

            foreach ($batch as $itemMap) {
                $this->assignOne($itemMap, $context, $result);
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
            'ItemAttributeSetAssignmentImporter run %s complete: assigned=%d updated=%d skipped=%d errors=%d needsReview=%d',
            $context->getRunId(),
            $result->getImported(),
            $result->getUpdated(),
            $result->getSkipped(),
            $result->getErrors(),
            $result->getNeedsReview()
        ));

        return $result;
    }

    private function assignOne(ItemMap $itemMap, ImportContext $context, ImportResult $result): void
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);
        $eccubeItemId = (int) $itemMap->getEccubeItemId();
        $magentoProductId = (int) $itemMap->getMagentoProductId();

        try {
            // Genuinely uncategorized in EC-CUBE (confirmed in the Round 32
            // report: 36 such items, all with zero dtb_category_item rows)
            // route to the dedicated "Uncategorized" set (Round 45 decision)
            // rather than being left unresolved.
            $topLevelCategoryId = $this->attributeSetResolver->resolveTopLevelCategoryId($eccubeItemId)
                ?? AttributeSetResolver::UNCATEGORIZED_TOP_LEVEL_ID;

            $setMap = $this->attributeSetMapRepository->getByTopLevelCategoryId($topLevelCategoryId);
            $rawTargetSetId = $setMap?->getMagentoAttributeSetId();

            if ($rawTargetSetId === null) {
                $result->incrementErrors();
                $message = sprintf('Resolved top-level category %d has no imported Magento attribute set yet.', $topLevelCategoryId);
                $this->logger->error(sprintf('Item %d attribute-set assignment failed: %s', $eccubeItemId, $message));
                $this->recordHistory($context, $eccubeItemId, $magentoProductId, SyncHistory::STATUS_ERROR, $message, $startTime, $startMemory);

                return;
            }

            // AbstractModel::getData() returns raw DB values (often
            // numeric strings, not int, despite the getter's phpdoc), and
            // the verification/idempotency checks below use strict
            // comparison against $actualSetId/$currentSetId (both cast to
            // int) - without this cast every comparison would fail even
            // when the values are logically equal.
            $targetSetId = (int) $rawTargetSetId;

            try {
                $product = $this->magentoProductRepository->getById($magentoProductId, true, 0);
            } catch (NoSuchEntityException $e) {
                $result->incrementErrors();
                $message = sprintf('Magento product %d no longer exists: %s', $magentoProductId, $e->getMessage());
                $this->logger->error(sprintf('Item %d attribute-set assignment failed: %s', $eccubeItemId, $message));
                $this->recordHistory($context, $eccubeItemId, $magentoProductId, SyncHistory::STATUS_ERROR, $message, $startTime, $startMemory);

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
                    '[DRY RUN] Would reassign Grouped Product id=%d (EC-CUBE item id=%d) from attribute_set_id=%d to %d',
                    $magentoProductId,
                    $eccubeItemId,
                    $currentSetId,
                    $targetSetId
                ));

                return;
            }

            $isUpdate = $itemMap->getSpecificationValueHash() !== null;

            $product->setAttributeSetId($targetSetId);
            $product->setData('store_id', 0);
            $this->magentoProductRepository->save($product);

            $reloaded = $this->magentoProductRepository->getById($magentoProductId, false, 0, true);
            $actualSetId = (int) $reloaded->getAttributeSetId();

            if ($actualSetId !== $targetSetId) {
                $result->incrementErrors();
                $message = sprintf('Verification failed: expected attribute_set_id=%d after save, actual=%d.', $targetSetId, $actualSetId);
                $this->logger->error(sprintf('Item %d attribute-set assignment failed verification: %s', $eccubeItemId, $message));
                $this->recordHistory($context, $eccubeItemId, $magentoProductId, SyncHistory::STATUS_ERROR, $message, $startTime, $startMemory);

                return;
            }

            if ($isUpdate) {
                $result->incrementUpdated();
            } else {
                $result->incrementImported();
            }

            $this->recordHistory(
                $context,
                $eccubeItemId,
                $magentoProductId,
                SyncHistory::STATUS_UPDATED,
                sprintf('Reassigned attribute_set_id %d -> %d', $currentSetId, $targetSetId),
                $startTime,
                $startMemory
            );
        } catch (\Throwable $e) {
            $result->incrementErrors();
            $this->logger->error(sprintf('Item %d attribute-set assignment failed: %s', $eccubeItemId, $e->getMessage()));
            $this->recordHistory($context, $eccubeItemId, $magentoProductId, SyncHistory::STATUS_ERROR, $e->getMessage(), $startTime, $startMemory);
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
            SyncHistory::ENTITY_TYPE_ITEM,
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
