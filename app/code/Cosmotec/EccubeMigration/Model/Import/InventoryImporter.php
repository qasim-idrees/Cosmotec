<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Import;

use Cosmotec\EccubeMigration\Api\Data\InventoryRecordInterface;
use Cosmotec\EccubeMigration\Api\ProductMapRepositoryInterface;
use Cosmotec\EccubeMigration\Api\SyncHistoryRepositoryInterface;
use Cosmotec\EccubeMigration\Logger\ImportLogger;
use Cosmotec\EccubeMigration\Model\DTO\MagentoInventory;
use Cosmotec\EccubeMigration\Model\Mapper\InventoryMapper;
use Cosmotec\EccubeMigration\Model\ProductMap;
use Cosmotec\EccubeMigration\Model\Reader\InventoryReader;
use Cosmotec\EccubeMigration\Model\SyncHistory;
use Cosmotec\EccubeMigration\Model\Validator\InventoryValidator;
use Magento\Framework\Exception\LocalizedException;
use Magento\InventoryApi\Api\Data\SourceItemInterface;
use Magento\InventoryApi\Api\Data\SourceItemInterfaceFactory;
use Magento\InventoryApi\Api\SourceItemsSaveInterface;
use Magento\InventoryCatalogApi\Api\DefaultSourceProviderInterface;

/**
 * Milestone 7 (Inventory), batched in Milestone 10 (Optimization).
 *
 * Reconciles dtb_product.stock_quantity into Magento via proper MSI source
 * items — replacing the legacy setStockData() baseline ProductImporter
 * (Milestone 5) applied at product creation time. Per the confirmed
 * reconciliation rule, dtb_product.stock_quantity always wins over
 * dtb_product_class stock even when class rows exist (InventoryMapper
 * enforces this).
 *
 * Optimization: rather than one SourceItemsSaveInterface::execute() call
 * per product (one MSI write round-trip per record), changed records are
 * buffered and flushed in batches of $context->getBatchSize() — a single
 * execute() call covers up to a whole batch of source items. Trade-off,
 * documented rather than hidden: if a flush call itself throws (a genuine
 * MSI-level failure, not an individual bad value — those are already
 * caught by validation before buffering), every record in that flush is
 * marked as an error, since Magento's SourceItemsSaveInterface does not
 * report which specific item in the batch failed. This trades a small
 * loss of per-record failure granularity, only in the rare case the whole
 * batch call itself throws, for a large reduction in DB round-trips on
 * every normal run.
 */
class InventoryImporter implements ImporterInterface
{
    private const DEFAULT_FLUSH_SIZE = 100;

    /**
     * @var array<int, array{sourceItem: SourceItemInterface, mapped: MagentoInventory, productMap: ProductMap, startTime: float, startMemory: int}>
     */
    private array $pending = [];

    public function __construct(
        private readonly InventoryReader $reader,
        private readonly InventoryValidator $validator,
        private readonly InventoryMapper $mapper,
        private readonly ProductMapRepositoryInterface $productMapRepository,
        private readonly SyncHistoryRepositoryInterface $syncHistoryRepository,
        private readonly SourceItemsSaveInterface $sourceItemsSave,
        private readonly SourceItemInterfaceFactory $sourceItemFactory,
        private readonly DefaultSourceProviderInterface $defaultSourceProvider,
        private readonly ImportLogger $logger
    ) {
    }

    public function import(ImportContext $context): ImportResult
    {
        $result = new ImportResult();
        $flushSize = $context->getBatchSize() ?? self::DEFAULT_FLUSH_SIZE;
        $this->pending = [];

        foreach ($this->reader->read(0, $context->getBatchSize()) as $record) {
            /** @var InventoryRecordInterface $record */
            $this->importOne($record, $context, $result);

            if (count($this->pending) >= $flushSize) {
                $this->flush($context, $result);
            }
        }

        $this->flush($context, $result);

        $this->logger->info(sprintf(
            'InventoryImporter run %s complete: imported=%d updated=%d skipped=%d errors=%d',
            $context->getRunId(),
            $result->getImported(),
            $result->getUpdated(),
            $result->getSkipped(),
            $result->getErrors()
        ));

        return $result;
    }

    private function importOne(InventoryRecordInterface $record, ImportContext $context, ImportResult $result): void
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);

        $productMap = $this->productMapRepository->getByEccubeProductId($record->getProductId());

        if ($productMap === null || $productMap->getMagentoProductId() === null || $productMap->getSku() === null) {
            // Product not imported yet — a later run (after
            // import:simple-products) will pick this up.
            $result->incrementSkipped();

            return;
        }

        try {
            $validation = $this->validator->validate($record);

            if (!$validation->isValid()) {
                $this->handleError($record, $productMap, $context, $result, $validation->getErrorsAsString(), $startTime, $startMemory);

                return;
            }

            $mapped = $this->mapper->map($record);

            if ($this->isUnchanged($productMap, $mapped)) {
                $result->incrementSkipped();
                $this->recordHistory($context, $record->getProductId(), (int) $productMap->getMagentoProductId(), SyncHistory::STATUS_SKIPPED, 'Unchanged', $startTime, $startMemory);

                return;
            }

            if ($context->isDryRun()) {
                $result->incrementImported();
                $this->logger->info(sprintf(
                    '[DRY RUN] Would set sku=%s qty=%s in_stock=%s (product id=%d)',
                    $mapped->getSku(),
                    $mapped->getQty(),
                    $mapped->isInStock() ? 'true' : 'false',
                    $record->getProductId()
                ));

                return;
            }

            $this->enqueue($mapped, $productMap, $startTime, $startMemory);
        } catch (LocalizedException | \Throwable $e) {
            $this->handleError($record, $productMap, $context, $result, $e->getMessage(), $startTime, $startMemory);
        }
    }

    private function isUnchanged(ProductMap $productMap, MagentoInventory $mapped): bool
    {
        return $productMap->getInventoryContentHash() === $mapped->getContentHash();
    }

    private function enqueue(MagentoInventory $mapped, ProductMap $productMap, float $startTime, int $startMemory): void
    {
        $sourceItem = $this->sourceItemFactory->create();
        $sourceItem->setSku($mapped->getSku());
        $sourceItem->setSourceCode($this->defaultSourceProvider->getCode());
        $sourceItem->setQuantity($mapped->getQty());
        $sourceItem->setStatus($mapped->isInStock()
            ? SourceItemInterface::STATUS_IN_STOCK
            : SourceItemInterface::STATUS_OUT_OF_STOCK);

        $this->pending[] = [
            'sourceItem' => $sourceItem,
            'mapped' => $mapped,
            'productMap' => $productMap,
            'startTime' => $startTime,
            'startMemory' => $startMemory,
        ];
    }

    private function flush(ImportContext $context, ImportResult $result): void
    {
        if ($this->pending === []) {
            return;
        }

        $batch = $this->pending;
        $this->pending = [];

        try {
            $this->sourceItemsSave->execute(array_column($batch, 'sourceItem'));
        } catch (LocalizedException | \Throwable $e) {
            $this->logger->error(sprintf(
                'InventoryImporter: batch of %d source item(s) failed to save, marking all as errors: %s',
                count($batch),
                $e->getMessage()
            ));

            foreach ($batch as $entry) {
                $result->incrementErrors();
                $entry['productMap']->setErrorMessage('Batch save failed: ' . $e->getMessage());

                if (!$context->isDryRun()) {
                    $this->productMapRepository->save($entry['productMap']);
                }

                $this->recordHistory(
                    $context,
                    $entry['mapped']->getEccubeProductId(),
                    $entry['productMap']->getMagentoProductId(),
                    SyncHistory::STATUS_ERROR,
                    'Batch save failed: ' . $e->getMessage(),
                    $entry['startTime'],
                    $entry['startMemory']
                );
            }

            return;
        }

        foreach ($batch as $entry) {
            $this->finalizeSuccess($entry['mapped'], $entry['productMap'], $context, $result, $entry['startTime'], $entry['startMemory']);
        }
    }

    private function finalizeSuccess(
        MagentoInventory $mapped,
        ProductMap $productMap,
        ImportContext $context,
        ImportResult $result,
        float $startTime,
        int $startMemory
    ): void {
        $isUpdate = $productMap->getInventoryContentHash() !== null;

        $productMap->setInventoryContentHash($mapped->getContentHash());
        $productMap->setInventorySyncedAt((new \DateTimeImmutable())->format('Y-m-d H:i:s'));
        $productMap->setErrorMessage(null);
        $this->productMapRepository->save($productMap);

        if ($isUpdate) {
            $result->incrementUpdated();
        } else {
            $result->incrementImported();
        }

        $this->logger->info(sprintf(
            'Inventory %s for sku=%s: qty=%s in_stock=%s',
            $isUpdate ? 'updated' : 'set',
            $mapped->getSku(),
            $mapped->getQty(),
            $mapped->isInStock() ? 'true' : 'false'
        ));

        $this->recordHistory(
            $context,
            $mapped->getEccubeProductId(),
            $productMap->getMagentoProductId(),
            $isUpdate ? SyncHistory::STATUS_UPDATED : SyncHistory::STATUS_IMPORTED,
            null,
            $startTime,
            $startMemory
        );
    }

    private function handleError(
        InventoryRecordInterface $record,
        ProductMap $productMap,
        ImportContext $context,
        ImportResult $result,
        string $message,
        float $startTime,
        int $startMemory
    ): void {
        $result->incrementErrors();
        $this->logger->error(sprintf('Inventory for product id=%d failed: %s', $record->getProductId(), $message));

        if (!$context->isDryRun()) {
            $productMap->setErrorMessage($message);
            $this->productMapRepository->save($productMap);
        }

        $this->recordHistory($context, $record->getProductId(), (int) $productMap->getMagentoProductId(), SyncHistory::STATUS_ERROR, $message, $startTime, $startMemory);
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

        $durationMs = (int) round((microtime(true) - $startTime) * 1000);
        $memoryBytes = max(0, memory_get_usage(true) - $startMemory);

        $this->syncHistoryRepository->record(
            $context->getRunId(),
            SyncHistory::ENTITY_TYPE_INVENTORY,
            SyncHistory::OPERATION_IMPORT,
            $sourceId,
            $targetId,
            $status,
            $message,
            $durationMs,
            $memoryBytes
        );
    }
}
