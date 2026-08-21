<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Import;

use Cosmotec\EccubeMigration\Api\Data\ItemInterface as EccubeItemInterface;
use Cosmotec\EccubeMigration\Api\Data\ItemSpecificationValueInterface;
use Cosmotec\EccubeMigration\Api\Data\SpecificationInterface;
use Cosmotec\EccubeMigration\Api\ItemMapRepositoryInterface;
use Cosmotec\EccubeMigration\Api\ItemSpecificationValueRepositoryInterface;
use Cosmotec\EccubeMigration\Api\SpecificationMapRepositoryInterface;
use Cosmotec\EccubeMigration\Api\SyncHistoryRepositoryInterface;
use Cosmotec\EccubeMigration\Logger\ImportLogger;
use Cosmotec\EccubeMigration\Model\ItemMap;
use Cosmotec\EccubeMigration\Model\Reader\ItemReader;
use Cosmotec\EccubeMigration\Model\Specification\MultiValueSpecificationRegistry;
use Cosmotec\EccubeMigration\Model\SyncHistory;
use Magento\Catalog\Api\ProductRepositoryInterface as MagentoProductRepositoryInterface;
use Magento\Catalog\Model\Product as MagentoProduct;
use Magento\Eav\Api\AttributeManagementInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Writes ITEM-scope (dtb_item_specification.type=1) specification values
 * onto the Grouped Product's EAV attributes ("Basic Information" per
 * docs/SPECIFICATION_MAGENTO_DATA_MODEL.md §2).
 *
 * NOT YET APPROVED FOR EXECUTION. Architecture only, per the continuation
 * prompt's explicit read-only-preparation instruction. Requires
 * AttributeImporter and AttributeSetImporter to have actually run first -
 * a specification with no eccube_specification_map row yet (attribute not
 * created) is skipped and retried on the next run, never an error.
 *
 * Grouped by item: every resolved value for one item is collected and
 * written in a single ProductRepository::save() call, matching the
 * existing module's performance rule (docs §20) rather than one save per
 * value.
 */
class ItemAttributeValueImporter implements ImporterInterface
{
    public function __construct(
        private readonly ItemReader $itemReader,
        private readonly ItemSpecificationValueRepositoryInterface $valueRepository,
        private readonly ItemMapRepositoryInterface $itemMapRepository,
        private readonly SpecificationMapRepositoryInterface $specificationMapRepository,
        private readonly MultiValueSpecificationRegistry $multiValueRegistry,
        private readonly SyncHistoryRepositoryInterface $syncHistoryRepository,
        private readonly MagentoProductRepositoryInterface $magentoProductRepository,
        private readonly AttributeManagementInterface $attributeManagement,
        private readonly ImportLogger $logger
    ) {
    }

    public function import(ImportContext $context): ImportResult
    {
        $result = new ImportResult();

        foreach ($this->itemReader->read(0, $context->getBatchSize()) as $item) {
            /** @var EccubeItemInterface $item */
            $this->importOne($item, $context, $result);
        }

        $this->logger->info(sprintf(
            'ItemAttributeValueImporter run %s complete: imported=%d updated=%d skipped=%d errors=%d',
            $context->getRunId(),
            $result->getImported(),
            $result->getUpdated(),
            $result->getSkipped(),
            $result->getErrors()
        ));

        return $result;
    }

    private function importOne(EccubeItemInterface $item, ImportContext $context, ImportResult $result): void
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);

        try {
            $itemMap = $this->itemMapRepository->getByEccubeItemId($item->getId());
            $magentoProductId = $this->toIntOrNull($itemMap?->getMagentoProductId());

            if ($itemMap === null || $magentoProductId === null) {
                // Grouped Product not imported yet - retried automatically
                // once import:group-products has processed it.
                $result->incrementSkipped();

                return;
            }

            $values = $this->valueRepository->getByItemId($item->getId());
            $hadPreviousValues = $itemMap->getSpecificationValueHash() !== null;

            if ($values === [] && !$hadPreviousValues) {
                // Never had any source values and still doesn't - nothing
                // to write and nothing to clear.
                $result->incrementSkipped();

                return;
            }

            $resolution = $this->resolveValues($values);

            // $values === [] here (with $hadPreviousValues true) means every
            // specification was removed at EC-CUBE source - unlike the
            // "unresolved/pending" case below, this must proceed to persist()
            // so the stale Magento values get cleared. If resolution is
            // empty only because an attribute/option isn't imported yet
            // (pendingCount > 0, $values !== []), the real source values
            // still exist and must never be wiped just because provisioning
            // hasn't caught up.
            $isSourceRemoval = $values === [] && $hadPreviousValues;

            if (!$isSourceRemoval && $resolution['values'] === []) {
                // Every value's attribute/option isn't imported yet -
                // retried automatically once AttributeImporter has run.
                $result->incrementSkipped();

                return;
            }

            $hash = $this->computeHash($resolution['values']);

            if ($itemMap->getSpecificationValueHash() === $hash) {
                $result->incrementSkipped();

                return;
            }

            if ($context->isDryRun()) {
                $result->incrementImported();
                $this->logger->info($isSourceRemoval
                    ? sprintf(
                        '[DRY RUN] Would CLEAR all eccube_spec_* attribute value(s) from Grouped Product id=%d (EC-CUBE item id=%d) - every specification was removed at source',
                        $magentoProductId,
                        $item->getId()
                    )
                    : sprintf(
                        '[DRY RUN] Would write %d attribute value(s) to Grouped Product id=%d (EC-CUBE item id=%d)%s',
                        count($resolution['values']),
                        $magentoProductId,
                        $item->getId(),
                        $resolution['pendingCount'] > 0
                            ? sprintf(', %d value(s) pending (attribute not yet created)', $resolution['pendingCount'])
                            : ''
                    ));

                return;
            }

            $isUpdate = $itemMap->getSpecificationValueHash() !== null;
            $failed = $this->persist($magentoProductId, $resolution['values']);

            if ($failed !== []) {
                $message = sprintf(
                    'Verification failed for %d of %d attribute value(s) after save: %s',
                    count($failed),
                    count($resolution['values']),
                    json_encode($failed, JSON_THROW_ON_ERROR)
                );
                $result->incrementErrors();
                $this->logger->error(sprintf('Item %d attribute values failed verification: %s', $item->getId(), $message));
                $this->recordHistory($context, $item->getId(), $magentoProductId, SyncHistory::STATUS_ERROR, $message, $startTime, $startMemory);

                return;
            }

            $itemMap->setSpecificationValueHash($hash);
            $itemMap->setSpecificationValuesSyncedAt((new \DateTimeImmutable())->format('Y-m-d H:i:s'));
            $this->itemMapRepository->save($itemMap);

            if ($isUpdate) {
                $result->incrementUpdated();
            } else {
                $result->incrementImported();
            }

            $this->recordHistory(
                $context,
                $item->getId(),
                $magentoProductId,
                $isUpdate ? SyncHistory::STATUS_UPDATED : SyncHistory::STATUS_IMPORTED,
                null,
                $startTime,
                $startMemory
            );
        } catch (LocalizedException | \Throwable $e) {
            $result->incrementErrors();
            $this->logger->error(sprintf('Item %d attribute values failed: %s', $item->getId(), $e->getMessage()));
            $this->recordHistory($context, $item->getId(), null, SyncHistory::STATUS_ERROR, $e->getMessage(), $startTime, $startMemory);
        }
    }

    /**
     * Resolves each source value to a [attribute_code => Magento value]
     * pair. Multi-value specifications (per MultiValueSpecificationRegistry
     * - PENDING DECISION) are collected into a comma-joined multiselect
     * value; every other specification is a plain select option id.
     *
     * @param ItemSpecificationValueInterface[] $values
     * @return array{values: array<string, int|string>, pendingCount: int}
     */
    private function resolveValues(array $values): array
    {
        $resolved = [];
        $multiValueBuckets = [];
        $pendingCount = 0;

        foreach ($values as $value) {
            $specMap = $this->specificationMapRepository->getBySpecificationId($value->getSpecificationId());

            if ($specMap === null
                || $specMap->getClassification() !== SpecificationInterface::CLASSIFICATION_CREATE
                || $specMap->getMagentoAttributeId() === null) {
                $pendingCount++;

                continue;
            }

            $optionMap = $this->specificationMapRepository->getOptionByClassId($value->getSpecificationClassId());

            if ($optionMap === null || $optionMap->getMagentoOptionId() === null) {
                $pendingCount++;

                continue;
            }

            if ($this->multiValueRegistry->isMultiValue($value->getSpecificationId())) {
                $multiValueBuckets[$specMap->getAttributeCode()][] = (int) $optionMap->getMagentoOptionId();
            } else {
                $resolved[$specMap->getAttributeCode()] = (int) $optionMap->getMagentoOptionId();
            }
        }

        foreach ($multiValueBuckets as $code => $optionIds) {
            $resolved[$code] = implode(',', array_unique($optionIds));
        }

        return ['values' => $resolved, 'pendingCount' => $pendingCount];
    }

    /**
     * @param array<string, int|string> $values
     */
    private function computeHash(array $values): string
    {
        ksort($values);

        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
    }

    /**
     * Writes the values, then reloads the product with a forced cache
     * bypass and re-checks every value against what Magento actually
     * persisted. Magento's EAV save silently drops values for attributes
     * outside the product's current attribute set (no exception - the root
     * cause of the Round 31 false-success bug: "Written: 878, Errors: 0"
     * with zero real EAV data), and silently coerces an invalid value on a
     * select/int attribute to 0 instead of failing (confirmed in the Round
     * 33 controlled experiment). Neither failure mode is detectable from
     * save() alone, so the caller must never trust a clean save() as proof
     * of a persisted value - only this comparison is.
     *
     * Also explicitly clears every eccube_spec_* attribute assigned to the
     * product's attribute set that is NOT in the current resolved value
     * set. Without this, a specification removed at EC-CUBE source leaves
     * its old Magento value stale forever - setData() only ever touches
     * codes present in $values, and Magento's save() does not clear
     * untouched attributes on its own (confirmed by a live test: cloning a
     * real product, calling this method with one fewer code than before,
     * the old value remained in catalog_product_entity_int unchanged).
     *
     * @param array<string, int|string> $values
     * @return array<string, array{expected: int|string, actual: mixed}> empty if every value (including clears) verified
     */
    private function persist(int $magentoProductId, array $values): array
    {
        try {
            $product = $this->magentoProductRepository->getById($magentoProductId, true, 0);
        } catch (NoSuchEntityException $e) {
            throw new \RuntimeException(sprintf('Magento product %d no longer exists: %s', $magentoProductId, $e->getMessage()), 0, $e);
        }

        $codesToClear = [];

        foreach ($this->attributeManagement->getAttributes(MagentoProduct::ENTITY, (string) $product->getAttributeSetId()) as $attribute) {
            $code = $attribute->getAttributeCode();

            if (str_starts_with($code, 'eccube_spec_') && !array_key_exists($code, $values)) {
                $codesToClear[] = $code;
                $product->setData($code, null);
            }
        }

        foreach ($values as $code => $value) {
            $product->setData($code, $value);
        }

        // Explicit store_id=0 on both the load above and here: without it
        // ProductRepository::save() falls back to StoreManager::getStore(),
        // which resolves to a real, non-global store view in a plain CLI
        // context - confirmed empirically during the media milestone. EAV
        // attribute values from this migration must land at global scope.
        $product->setData('store_id', 0);
        $this->magentoProductRepository->save($product);

        $reloaded = $this->magentoProductRepository->getById($magentoProductId, false, 0, true);

        $failed = [];

        foreach ($values as $code => $expected) {
            $actual = $reloaded->getData($code);

            if ((string) $actual !== (string) $expected) {
                $failed[$code] = ['expected' => $expected, 'actual' => $actual];
            }
        }

        foreach ($codesToClear as $code) {
            $actual = $reloaded->getData($code);

            if ($actual !== null) {
                $failed[$code] = ['expected' => null, 'actual' => $actual];
            }
        }

        return $failed;
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
            SyncHistory::ENTITY_TYPE_ITEM,
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
