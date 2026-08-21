<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Import;

use Cosmotec\EccubeMigration\Api\Data\ProductSpecificationValueInterface;
use Cosmotec\EccubeMigration\Api\Data\SpecificationInterface;
use Cosmotec\EccubeMigration\Api\ProductMapRepositoryInterface;
use Cosmotec\EccubeMigration\Api\ProductSpecificationValueMapRepositoryInterface;
use Cosmotec\EccubeMigration\Api\ProductSpecificationValueRepositoryInterface;
use Cosmotec\EccubeMigration\Api\SpecificationMapRepositoryInterface;
use Cosmotec\EccubeMigration\Api\SyncHistoryRepositoryInterface;
use Cosmotec\EccubeMigration\Logger\ImportLogger;
use Cosmotec\EccubeMigration\Model\ProductMap;
use Cosmotec\EccubeMigration\Model\ProductSpecificationValueMap;
use Cosmotec\EccubeMigration\Model\ProductSpecificationValueMapFactory;
use Cosmotec\EccubeMigration\Model\Specification\MultiValueSpecificationRegistry;
use Cosmotec\EccubeMigration\Model\SyncHistory;
use Magento\Catalog\Api\ProductRepositoryInterface as MagentoProductRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Writes PRODUCT-scope (dtb_item_specification.type=2, values in
 * dtb_product_specification_class) specification values onto the Simple
 * Product's EAV attributes (Model List / Filter by Model per
 * docs/SPECIFICATION_MAGENTO_DATA_MODEL.md §3-4).
 *
 * NOT YET APPROVED FOR EXECUTION. Architecture only.
 *
 * Iterates via ProductSpecificationValueRepository::getProductIdsWithValues()
 * rather than the full ProductReader, so products with zero specification
 * data (the large majority - only a subset of ~27,590 products carry
 * values) never cost a query. Grouped by product: every resolved value is
 * written in one ProductRepository::save() call (docs §20 - reduces
 * 193,473 rows to at most one save per product with values).
 *
 * Multi-value handling (PENDING DECISION, see
 * MultiValueSpecificationRegistry): for the 5 flagged specifications,
 * every value is preserved losslessly in eccube_product_specification_value
 * (position = source row insertion order - dtb_product_specification_class
 * has no sort_no, verified live) AND written to the Magento attribute as a
 * comma-joined multiselect value so layered navigation matches either
 * value (docs §4 - filter has no positional constraint). Every other
 * specification is a plain select write.
 */
class ProductAttributeValueImporter implements ImporterInterface
{
    public function __construct(
        private readonly ProductSpecificationValueRepositoryInterface $valueRepository,
        private readonly ProductSpecificationValueMapRepositoryInterface $positionalMapRepository,
        private readonly ProductSpecificationValueMapFactory $positionalMapFactory,
        private readonly ProductMapRepositoryInterface $productMapRepository,
        private readonly SpecificationMapRepositoryInterface $specificationMapRepository,
        private readonly MultiValueSpecificationRegistry $multiValueRegistry,
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
     * @param int|null $limit stop after this many products-with-values -
     *                        e.g. for a small real-data smoke test. Unlike
     *                        the other importers' --limit (which caps
     *                        source rows), this caps PRODUCTS, since each
     *                        one drives a variable number of value rows.
     */
    public function importLimited(ImportContext $context, ?int $limit): ImportResult
    {
        $result = new ImportResult();
        $batchSize = $context->getBatchSize() ?? 100;
        $offset = 0;
        $processed = 0;

        while (true) {
            $productIds = $this->valueRepository->getProductIdsWithValues($offset, $batchSize);

            if ($productIds === []) {
                break;
            }

            foreach ($productIds as $eccubeProductId) {
                $this->importOne($eccubeProductId, $context, $result);
                $processed++;

                if ($limit !== null && $processed >= $limit) {
                    break 2;
                }
            }

            if (count($productIds) < $batchSize) {
                break;
            }

            $offset += $batchSize;
        }

        $this->logger->info(sprintf(
            'ProductAttributeValueImporter run %s complete: imported=%d updated=%d skipped=%d errors=%d',
            $context->getRunId(),
            $result->getImported(),
            $result->getUpdated(),
            $result->getSkipped(),
            $result->getErrors()
        ));

        return $result;
    }

    private function importOne(int $eccubeProductId, ImportContext $context, ImportResult $result): void
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);

        try {
            $productMap = $this->productMapRepository->getByEccubeProductId($eccubeProductId);
            $magentoProductId = $this->toIntOrNull($productMap?->getMagentoProductId());

            if ($productMap === null || $magentoProductId === null) {
                // Simple Product not imported yet - retried automatically
                // once import:simple-products has processed it.
                $result->incrementSkipped();

                return;
            }

            $values = $this->valueRepository->getByProductId($eccubeProductId);

            if ($values === []) {
                $result->incrementSkipped();

                return;
            }

            $resolution = $this->resolveValues($values);

            if ($resolution['eavValues'] === [] && $resolution['positional'] === []) {
                $result->incrementSkipped();

                return;
            }

            $hash = $this->computeHash($resolution['eavValues']);

            if ($productMap->getSpecificationValueHash() === $hash) {
                $result->incrementSkipped();

                return;
            }

            if ($context->isDryRun()) {
                $result->incrementImported();
                $this->logger->info(sprintf(
                    '[DRY RUN] Would write %d attribute value(s) (%d multi-value row(s)) to Simple Product id=%d (EC-CUBE product id=%d)%s',
                    count($resolution['eavValues']),
                    count($resolution['positional']),
                    $magentoProductId,
                    $eccubeProductId,
                    $resolution['pendingCount'] > 0
                        ? sprintf(', %d value(s) pending (attribute not yet created)', $resolution['pendingCount'])
                        : ''
                ));

                return;
            }

            $isUpdate = $productMap->getSpecificationValueHash() !== null;
            $failed = [];

            if ($resolution['eavValues'] !== []) {
                $failed = $this->persistEav($magentoProductId, $resolution['eavValues']);
            }

            if ($failed !== []) {
                $message = sprintf(
                    'Verification failed for %d of %d attribute value(s) after save: %s',
                    count($failed),
                    count($resolution['eavValues']),
                    json_encode($failed, JSON_THROW_ON_ERROR)
                );
                $result->incrementErrors();
                $this->logger->error(sprintf('Product %d attribute values failed verification: %s', $eccubeProductId, $message));
                $this->recordHistory($context, $eccubeProductId, $magentoProductId, SyncHistory::STATUS_ERROR, $message, $startTime, $startMemory);

                return;
            }

            $this->persistPositional($resolution['positional'], $magentoProductId);

            $productMap->setSpecificationValueHash($hash);
            $productMap->setSpecificationValuesSyncedAt((new \DateTimeImmutable())->format('Y-m-d H:i:s'));
            $this->productMapRepository->save($productMap);

            if ($isUpdate) {
                $result->incrementUpdated();
            } else {
                $result->incrementImported();
            }

            $this->recordHistory(
                $context,
                $eccubeProductId,
                $magentoProductId,
                $isUpdate ? SyncHistory::STATUS_UPDATED : SyncHistory::STATUS_IMPORTED,
                null,
                $startTime,
                $startMemory
            );
        } catch (LocalizedException | \Throwable $e) {
            $result->incrementErrors();
            $this->logger->error(sprintf('Product %d attribute values failed: %s', $eccubeProductId, $e->getMessage()));
            $this->recordHistory($context, $eccubeProductId, null, SyncHistory::STATUS_ERROR, $e->getMessage(), $startTime, $startMemory);
        }
    }

    /**
     * @param ProductSpecificationValueInterface[] $values
     * @return array{
     *     eavValues: array<string, int|string>,
     *     positional: array<int, array{value: ProductSpecificationValueInterface, attributeCode: string, magentoOptionId: int, position: int}>,
     *     pendingCount: int
     * }
     */
    private function resolveValues(array $values): array
    {
        $eavValues = [];
        $multiValueBuckets = [];
        $positional = [];
        $pendingCount = 0;
        $positionCounters = [];

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

            $magentoOptionId = (int) $optionMap->getMagentoOptionId();

            if ($this->multiValueRegistry->isMultiValue($value->getSpecificationId())) {
                $multiValueBuckets[$specMap->getAttributeCode()][] = $magentoOptionId;

                $position = $positionCounters[$value->getSpecificationId()] ?? 0;
                $positionCounters[$value->getSpecificationId()] = $position + 1;

                $positional[] = [
                    'value' => $value,
                    'attributeCode' => $specMap->getAttributeCode(),
                    'magentoOptionId' => $magentoOptionId,
                    'position' => $position,
                ];
            } else {
                $eavValues[$specMap->getAttributeCode()] = $magentoOptionId;
            }
        }

        foreach ($multiValueBuckets as $code => $optionIds) {
            $eavValues[$code] = implode(',', array_unique($optionIds));
        }

        return ['eavValues' => $eavValues, 'positional' => $positional, 'pendingCount' => $pendingCount];
    }

    /**
     * @param array<string, int|string> $eavValues
     */
    private function computeHash(array $eavValues): string
    {
        ksort($eavValues);

        return hash('sha256', json_encode($eavValues, JSON_THROW_ON_ERROR));
    }

    /**
     * Writes the values, then reloads the product with a forced cache
     * bypass and re-checks every value against what Magento actually
     * persisted. See ItemAttributeValueImporter::persist() for the two
     * confirmed silent-failure modes this guards against (value dropped for
     * an attribute outside the product's attribute set; invalid value on a
     * select attribute coerced to 0) - neither raises an exception, so a
     * clean save() is never proof of a persisted value on its own.
     *
     * @param array<string, int|string> $eavValues
     * @return array<string, array{expected: int|string, actual: mixed}> empty if every value verified
     */
    private function persistEav(int $magentoProductId, array $eavValues): array
    {
        try {
            $product = $this->magentoProductRepository->getById($magentoProductId, true, 0);
        } catch (NoSuchEntityException $e) {
            throw new \RuntimeException(sprintf('Magento product %d no longer exists: %s', $magentoProductId, $e->getMessage()), 0, $e);
        }

        foreach ($eavValues as $code => $value) {
            $product->setData($code, $value);
        }

        // See ItemAttributeValueImporter::persist() for why store_id=0 is
        // set explicitly on both the load and the save.
        $product->setData('store_id', 0);
        $this->magentoProductRepository->save($product);

        $reloaded = $this->magentoProductRepository->getById($magentoProductId, false, 0, true);

        $failed = [];

        foreach ($eavValues as $code => $expected) {
            $actual = $reloaded->getData($code);

            if ((string) $actual !== (string) $expected) {
                $failed[$code] = ['expected' => $expected, 'actual' => $actual];
            }
        }

        return $failed;
    }

    /**
     * Lossless positional record - PENDING DECISION, see
     * MultiValueSpecificationRegistry. Idempotent per source row via the
     * eccube_product_specification_class_id unique key.
     *
     * @param array<int, array{value: ProductSpecificationValueInterface, attributeCode: string, magentoOptionId: int, position: int}> $positional
     */
    private function persistPositional(array $positional, int $magentoProductId): void
    {
        foreach ($positional as $entry) {
            /** @var ProductSpecificationValueInterface $value */
            $value = $entry['value'];
            $existing = $this->positionalMapRepository->getBySourceRowId($value->getId());
            /** @var ProductSpecificationValueMap $map */
            $map = $existing ?? $this->positionalMapFactory->create();
            $map->setEccubeProductSpecificationClassId($value->getId());
            $map->setEccubeProductId($value->getProductId());
            $map->setEccubeSpecificationId($value->getSpecificationId());
            $map->setEccubeSpecificationClassId($value->getSpecificationClassId());
            $map->setMagentoOptionId($entry['magentoOptionId']);
            $map->setPosition($entry['position']);
            $map->setMagentoProductId($magentoProductId);
            $map->setStatus($existing !== null ? ProductSpecificationValueMap::STATUS_UPDATED : ProductSpecificationValueMap::STATUS_IMPORTED);
            $map->setErrorMessage(null);
            $map->setLastSyncedAt((new \DateTimeImmutable())->format('Y-m-d H:i:s'));
            $this->positionalMapRepository->save($map);
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
            SyncHistory::ENTITY_TYPE_PRODUCT,
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
