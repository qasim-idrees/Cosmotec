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
use Cosmotec\EccubeMigration\Api\ItemMapRepositoryInterface;
use Cosmotec\EccubeMigration\Api\SyncHistoryRepositoryInterface;
use Cosmotec\EccubeMigration\Logger\ImportLogger;
use Cosmotec\EccubeMigration\Model\DTO\MagentoParentProduct;
use Cosmotec\EccubeMigration\Model\ItemMap;
use Cosmotec\EccubeMigration\Model\ItemMapFactory;
use Cosmotec\EccubeMigration\Model\Mapper\ItemMapper;
use Cosmotec\EccubeMigration\Model\Reader\ItemReader;
use Cosmotec\EccubeMigration\Model\SyncHistory;
use Cosmotec\EccubeMigration\Model\UrlKey\UrlKeyResolver;
use Cosmotec\EccubeMigration\Model\Validator\ItemValidator;
use Magento\Catalog\Api\CategoryLinkManagementInterface;
use Magento\Catalog\Model\Product as MagentoProduct;
use Magento\Catalog\Model\Product\Attribute\Source\Status as ProductStatus;
use Magento\Catalog\Api\Data\ProductInterfaceFactory as MagentoProductFactory;
use Magento\Catalog\Api\ProductRepositoryInterface as MagentoProductRepositoryInterface;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Milestone 4 (Group Products). Creates the Grouped Product "shell" for
 * each dtb_item — name, sku, status, visibility, categories. It does NOT
 * link any child simple products yet, since those are created in
 * Milestone 5; that linking ("Group Relations" in the spec's Import Order)
 * happens as the final step of the Simple Products importer once both
 * sides of the relationship exist.
 */
class ItemImporter implements ImporterInterface
{
    public function __construct(
        private readonly ItemReader $reader,
        private readonly ItemValidator $validator,
        private readonly ItemMapper $mapper,
        protected readonly ItemMapRepositoryInterface $itemMapRepository,
        private readonly ItemMapFactory $itemMapFactory,
        private readonly SyncHistoryRepositoryInterface $syncHistoryRepository,
        private readonly MagentoProductRepositoryInterface $magentoProductRepository,
        private readonly MagentoProductFactory $magentoProductFactory,
        private readonly CategoryLinkManagementInterface $categoryLinkManagement,
        private readonly StoreManagerInterface $storeManager,
        private readonly UrlKeyResolver $urlKeyResolver,
        protected readonly ImportLogger $logger
    ) {
    }

    public function import(ImportContext $context): ImportResult
    {
        $result = new ImportResult();

        foreach ($this->reader->read(0, $context->getBatchSize()) as $source) {
            /** @var EccubeItemInterface $source */
            $this->importOne($source, $context, $result);
        }

        $this->logger->info(sprintf(
            'ItemImporter run %s complete: imported=%d updated=%d skipped=%d errors=%d',
            $context->getRunId(),
            $result->getImported(),
            $result->getUpdated(),
            $result->getSkipped(),
            $result->getErrors()
        ));

        return $result;
    }

    protected function importOne(EccubeItemInterface $source, ImportContext $context, ImportResult $result): void
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);

        try {
            $validation = $this->validator->validate($source);

            if (!$validation->isValid()) {
                $this->handleError($source, $context, $result, $validation->getErrorsAsString(), $startTime, $startMemory);

                return;
            }

            $existingMap = $this->itemMapRepository->getByEccubeItemId($source->getId());

            if ($this->isAlreadyDone($existingMap)) {
                $result->incrementSkipped();
                $this->logger->info(sprintf('Item id=%d already imported, skipping.', $source->getId()));
                $this->recordHistory($context, $source->getId(), $existingMap?->getMagentoProductId() !== null ? (int) $existingMap->getMagentoProductId() : null, SyncHistory::STATUS_SKIPPED, 'Already imported', $startTime, $startMemory);

                return;
            }

            $mapped = $this->mapper->map($source);

            if ($context->isDryRun()) {
                $result->incrementImported();
                $this->logger->info(sprintf(
                    '[DRY RUN] Would %s Magento %s product "%s" sku=%s (EC-CUBE item id=%d)',
                    $existingMap?->getMagentoProductId() !== null ? 'update' : 'create',
                    $mapped->getTypeId(),
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

    private function isAlreadyDone(?ItemMap $map): bool
    {
        if ($map === null || $map->getMagentoProductId() === null) {
            return false;
        }

        return in_array($map->getStatus(), [ItemMap::STATUS_IMPORTED, ItemMap::STATUS_UPDATED], true);
    }

    private function persist(
        MagentoParentProduct $mapped,
        ?ItemMap $existingMap,
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
                // Mapped product was deleted out-of-band; fall back to creating a new one.
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

        if (!$isUpdate) {
            $magentoProduct->setTypeId($mapped->getTypeId());
            // StoreManagerInterface::getWebsite() (no arg) resolves the
            // "current" website from ambient request/area context, which is
            // unreliable in a plain CLI/cron process - live-confirmed this
            // session: 19,072 of 28,277 catalog_product_website rows ended
            // up on website_id=0 ("Admin", not a real storefront website),
            // making the affected products invisible on the storefront and
            // preventing URL rewrite generation. getWebsites() has no such
            // ambient dependency - it always returns the real, non-admin
            // websites regardless of execution context.
            $magentoProduct->setWebsiteIds(array_keys($this->storeManager->getWebsites()));
            // Only ever set at creation, never on update - see
            // UrlKeyResolver's docblock for the full deterministic
            // collision-handling algorithm. Computing it only here (not in
            // the Mapper, which has no Magento-side state) is what makes an
            // already-imported product's url_key stable even if its
            // EC-CUBE name is edited later - re-imports never reach this
            // branch, so a later name change cannot retroactively change
            // the URL.
            $magentoProduct->setUrlKey($this->urlKeyResolver->resolveForItem($mapped->getEccubeItemId()));
        }

        $saved = $this->magentoProductRepository->save($magentoProduct);
        $magentoProductId = (int) $saved->getId();

        if ($mapped->getCategoryIds() !== []) {
            $this->categoryLinkManagement->assignProductToCategories($saved->getSku(), $mapped->getCategoryIds());
        }

        /** @var ItemMap $map */
        $map = $existingMap ?? $this->itemMapFactory->create();
        $map->setEccubeItemId($mapped->getEccubeItemId());
        $map->setMagentoProductId($magentoProductId);
        $map->setSku($mapped->getSku());
        $map->setContentHash($mapped->getContentHash());
        $map->setStatus($isUpdate ? ItemMap::STATUS_UPDATED : ItemMap::STATUS_IMPORTED);
        $map->setErrorMessage(null);
        $map->setLastSyncedAt((new \DateTimeImmutable())->format('Y-m-d H:i:s'));
        $this->itemMapRepository->save($map);

        if ($isUpdate) {
            $result->incrementUpdated();
        } else {
            $result->incrementImported();
        }

        $this->logger->info(sprintf(
            'Item id=%d %s as Magento product id=%d sku=%s',
            $mapped->getEccubeItemId(),
            $isUpdate ? 'updated' : 'imported',
            $magentoProductId,
            $mapped->getSku()
        ));

        $this->recordHistory(
            $context,
            $mapped->getEccubeItemId(),
            $magentoProductId,
            $isUpdate ? SyncHistory::STATUS_UPDATED : SyncHistory::STATUS_IMPORTED,
            null,
            $startTime,
            $startMemory
        );
    }

    private function newProduct(): MagentoProduct
    {
        $product = $this->magentoProductFactory->create();
        $product->setVisibility(Visibility::VISIBILITY_BOTH);

        return $product;
    }

    private function handleError(
        EccubeItemInterface $source,
        ImportContext $context,
        ImportResult $result,
        string $message,
        float $startTime,
        int $startMemory
    ): void {
        $result->incrementErrors();
        $this->logger->error(sprintf('Item id=%d failed: %s', $source->getId(), $message));

        if (!$context->isDryRun()) {
            $existingMap = $this->itemMapRepository->getByEccubeItemId($source->getId());
            /** @var ItemMap $map */
            $map = $existingMap ?? $this->itemMapFactory->create();
            $map->setEccubeItemId($source->getId());
            $map->setStatus(ItemMap::STATUS_ERROR);
            $map->setErrorMessage($message);
            $this->itemMapRepository->save($map);
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
            SyncHistory::ENTITY_TYPE_ITEM,
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
