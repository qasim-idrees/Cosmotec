<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Import;

use Cosmotec\EccubeMigration\Api\Data\ProductInterface as EccubeProductInterface;
use Cosmotec\EccubeMigration\Api\ProductMapRepositoryInterface;
use Cosmotec\EccubeMigration\Api\SyncHistoryRepositoryInterface;
use Cosmotec\EccubeMigration\Logger\ImportLogger;
use Cosmotec\EccubeMigration\Model\DTO\MagentoSimpleProduct;
use Cosmotec\EccubeMigration\Model\Mapper\ProductMapper;
use Cosmotec\EccubeMigration\Model\ProductMap;
use Cosmotec\EccubeMigration\Model\ProductMapFactory;
use Cosmotec\EccubeMigration\Model\Reader\ProductReader;
use Cosmotec\EccubeMigration\Model\SyncHistory;
use Cosmotec\EccubeMigration\Model\Validator\ProductValidator;
use Magento\Catalog\Model\Product as MagentoProduct;
use Magento\Catalog\Model\Product\Attribute\Source\Status as ProductStatus;
use Magento\Catalog\Api\Data\ProductInterfaceFactory as MagentoProductFactory;
use Magento\Catalog\Api\ProductRepositoryInterface as MagentoProductRepositoryInterface;
use Magento\Catalog\Model\Product\Type as MagentoProductType;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Milestone 5 (Simple Products) of the import pipeline. Creates the actual
 * dtb_product -> Magento Simple Product. Does NOT link products to their
 * parent Grouped Product here — see ProductRelationImporter, which the spec
 * treats as its own Import Order step (6, "Group Relations") and its own
 * CLI command (import:product-relations), run after this one.
 */
class ProductImporter implements ImporterInterface
{
    public function __construct(
        private readonly ProductReader $reader,
        private readonly ProductValidator $validator,
        private readonly ProductMapper $mapper,
        protected readonly ProductMapRepositoryInterface $productMapRepository,
        private readonly ProductMapFactory $productMapFactory,
        private readonly SyncHistoryRepositoryInterface $syncHistoryRepository,
        private readonly MagentoProductRepositoryInterface $magentoProductRepository,
        private readonly MagentoProductFactory $magentoProductFactory,
        private readonly StoreManagerInterface $storeManager,
        protected readonly ImportLogger $logger
    ) {
    }

    public function import(ImportContext $context): ImportResult
    {
        $result = new ImportResult();

        foreach ($this->reader->read(0, $context->getBatchSize()) as $source) {
            /** @var EccubeProductInterface $source */
            $this->importOne($source, $context, $result);
        }

        $this->logger->info(sprintf(
            'ProductImporter run %s complete: imported=%d updated=%d skipped=%d errors=%d',
            $context->getRunId(),
            $result->getImported(),
            $result->getUpdated(),
            $result->getSkipped(),
            $result->getErrors()
        ));

        return $result;
    }

    protected function importOne(EccubeProductInterface $source, ImportContext $context, ImportResult $result): void
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);

        try {
            $validation = $this->validator->validate($source);

            if (!$validation->isValid()) {
                $this->handleError($source, $context, $result, $validation->getErrorsAsString(), $startTime, $startMemory);

                return;
            }

            $existingMap = $this->productMapRepository->getByEccubeProductId($source->getId());

            if ($this->isAlreadyDone($existingMap)) {
                $result->incrementSkipped();
                $this->logger->info(sprintf('Product id=%d already imported, skipping.', $source->getId()));
                $this->recordHistory($context, $source->getId(), $existingMap?->getMagentoProductId() !== null ? (int) $existingMap->getMagentoProductId() : null, SyncHistory::STATUS_SKIPPED, 'Already imported', $startTime, $startMemory);

                return;
            }

            $mapped = $this->mapper->map($source);

            if ($context->isDryRun()) {
                $result->incrementImported();
                $this->logger->info(sprintf(
                    '[DRY RUN] Would %s Magento simple product "%s" sku=%s (EC-CUBE product id=%d)',
                    $existingMap?->getMagentoProductId() !== null ? 'update' : 'create',
                    $mapped->getName(),
                    $mapped->getSku(),
                    $source->getId()
                ));

                return;
            }

            $this->persist($mapped, $existingMap, $context, $result, $startTime, $startMemory);
        } catch (LocalizedException | \Throwable $e) {
            $this->handleError($source, $context, $result, $e->getMessage(), $startTime, $startMemory);
        }
    }

    private function isAlreadyDone(?ProductMap $map): bool
    {
        if ($map === null || $map->getMagentoProductId() === null) {
            return false;
        }

        return in_array($map->getStatus(), [ProductMap::STATUS_IMPORTED, ProductMap::STATUS_UPDATED], true);
    }

    private function persist(
        MagentoSimpleProduct $mapped,
        ?ProductMap $existingMap,
        ImportContext $context,
        ImportResult $result,
        float $startTime,
        int $startMemory
    ): void {
        $isUpdate = $existingMap !== null && $existingMap->getMagentoProductId() !== null;

        if ($isUpdate) {
            try {
                $magentoProduct = $this->magentoProductRepository->getById((int) $existingMap->getMagentoProductId());
            } catch (NoSuchEntityException) {
                $magentoProduct = $this->newProduct();
                $isUpdate = false;
            }
        } else {
            $magentoProduct = $this->newProduct();
        }

        $magentoProduct->setSku($mapped->getSku());
        $magentoProduct->setName($mapped->getName());
        $magentoProduct->setStatus($mapped->isEnabled()
            ? ProductStatus::STATUS_ENABLED
            : ProductStatus::STATUS_DISABLED);
        $magentoProduct->setVisibility($mapped->getVisibility());
        $magentoProduct->setAttributeSetId($mapped->getAttributeSetId());
        // dtb_product.cad_unavailable_check -> boolean EAV attribute.
        // Set via setCustomAttribute so it is skipped silently if the
        // attribute has not been created yet, rather than failing the
        // whole product import.
        $magentoProduct->setCustomAttribute('cad_unavailable', $mapped->isCadUnavailable() ? 1 : 0);

        if ($mapped->getPrice() !== null) {
            $magentoProduct->setPrice((float) $mapped->getPrice());
        }

        // Baseline legacy stock data so the product is immediately
        // salable/visible; Milestone 7 (Inventory) reconciles this against
        // dtb_product_class variant stock via proper MSI source items.
        $magentoProduct->setStockData([
            'qty' => $mapped->getStockQuantity(),
            'is_in_stock' => $mapped->isInStock(),
            'manage_stock' => 1,
        ]);

        if (!$isUpdate) {
            $magentoProduct->setTypeId(MagentoProductType::TYPE_SIMPLE);
            $magentoProduct->setWebsiteIds([(int) $this->storeManager->getWebsite()->getId()]);
        }

        $saved = $this->magentoProductRepository->save($magentoProduct);
        $magentoProductId = (int) $saved->getId();

        /** @var ProductMap $map */
        $map = $existingMap ?? $this->productMapFactory->create();
        $map->setEccubeProductId($mapped->getEccubeProductId());
        $map->setEccubeItemId($mapped->getEccubeItemId());
        $map->setMagentoProductId($magentoProductId);
        $map->setSku($mapped->getSku());
        $map->setContentHash($mapped->getContentHash());
        $map->setStatus($isUpdate ? ProductMap::STATUS_UPDATED : ProductMap::STATUS_IMPORTED);

        if (!$isUpdate) {
            $map->setRelationLinked(0);
        }

        $map->setErrorMessage(null);
        $map->setLastSyncedAt((new \DateTimeImmutable())->format('Y-m-d H:i:s'));
        $this->productMapRepository->save($map);

        if ($isUpdate) {
            $result->incrementUpdated();
        } else {
            $result->incrementImported();
        }

        $this->logger->info(sprintf(
            'Product id=%d %s as Magento product id=%d sku=%s',
            $mapped->getEccubeProductId(),
            $isUpdate ? 'updated' : 'imported',
            $magentoProductId,
            $mapped->getSku()
        ));

        $this->recordHistory(
            $context,
            $mapped->getEccubeProductId(),
            $magentoProductId,
            $isUpdate ? SyncHistory::STATUS_UPDATED : SyncHistory::STATUS_IMPORTED,
            null,
            $startTime,
            $startMemory
        );
    }

    private function newProduct(): MagentoProduct
    {
        return $this->magentoProductFactory->create();
    }

    private function handleError(
        EccubeProductInterface $source,
        ImportContext $context,
        ImportResult $result,
        string $message,
        float $startTime,
        int $startMemory
    ): void {
        $result->incrementErrors();
        $this->logger->error(sprintf('Product id=%d failed: %s', $source->getId(), $message));

        if (!$context->isDryRun()) {
            $existingMap = $this->productMapRepository->getByEccubeProductId($source->getId());
            /** @var ProductMap $map */
            $map = $existingMap ?? $this->productMapFactory->create();
            $map->setEccubeProductId($source->getId());
            $map->setEccubeItemId($source->getItemId());
            $map->setStatus(ProductMap::STATUS_ERROR);
            $map->setErrorMessage($message);
            $this->productMapRepository->save($map);
        }

        $this->recordHistory($context, $source->getId(), null, SyncHistory::STATUS_ERROR, $message, $startTime, $startMemory);
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
            SyncHistory::ENTITY_TYPE_PRODUCT,
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
