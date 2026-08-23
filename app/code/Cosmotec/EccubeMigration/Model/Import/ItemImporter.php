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
use Cosmotec\EccubeMigration\Model\UrlKey\UrlKeyCollisionChecker;
use Cosmotec\EccubeMigration\Model\UrlKey\UrlKeyFallbackGenerator;
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
        private readonly UrlKeyFallbackGenerator $urlKeyFallbackGenerator,
        private readonly UrlKeyCollisionChecker $urlKeyCollisionChecker,
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
            $mapped = $this->mapper->map($source);

            // Gated on BOTH status and content hash - a plain status check
            // (the previous behavior) meant any item that ever reached
            // IMPORTED/UPDATED could never be touched again by either
            // import:group-products or sync:group-products, no matter what
            // changed in EC-CUBE source afterward - the exact same bug
            // found and fixed in CategoryImporter (see BUILD_STATUS.md),
            // now confirmed to affect this importer too.
            if ($this->isAlreadyDone($existingMap) && $existingMap->getContentHash() === $mapped->getContentHash()) {
                $result->incrementSkipped();
                $this->logger->info(sprintf('Item id=%d already imported and unchanged, skipping.', $source->getId()));
                $this->recordHistory($context, $source->getId(), $existingMap?->getMagentoProductId() !== null ? (int) $existingMap->getMagentoProductId() : null, SyncHistory::STATUS_SKIPPED, 'Already imported and unchanged', $startTime, $startMemory);

                return;
            }

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
                // Explicit store_id=0 (global scope) - same fix as
                // ProductImporter::persist(), same recurring bug class
                // found 8+ times this session. Without it, both this load
                // and save() below ambiently resolve to whatever store
                // StoreManager::getStore() returns in CLI context.
                $magentoProduct = $this->magentoProductRepository->getById((int) $existingMap->getMagentoProductId(), false, 0);
            } catch (NoSuchEntityException) {
                // Mapped product was deleted out-of-band; fall back to creating a new one.
                $magentoProduct = $this->newProduct();
                $isUpdate = false;
            }
        } else {
            $magentoProduct = $this->newProduct();
        }

        $magentoProduct->setData('store_id', 0);
        $magentoProduct->setSku($mapped->getSku());
        $magentoProduct->setName($mapped->getName());
        $magentoProduct->setStatus($mapped->isEnabled()
            ? ProductStatus::STATUS_ENABLED
            : ProductStatus::STATUS_DISABLED);
        $magentoProduct->setVisibility($mapped->getVisibility());

        if (!$isUpdate) {
            // Only ever set at creation, never on update. $mapped->
            // getAttributeSetId() is always Magento's Default set
            // (DefaultAttributeSetProvider) - ItemMapper has no way to
            // compute the real EC-CUBE-category-derived attribute set,
            // which is assigned separately and later by
            // assign:item-attribute-sets. Setting this unconditionally on
            // every persist() would silently revert an already-correctly-
            // assigned item back to Default on its next update - orphaning
            // every ecs_* value already written against the real
            // attribute set. Same fix as ProductImporter, applied here
            // before it could ever be triggered (items currently show 0
            // pending hash-gate updates, but the bug was equally real).
            $magentoProduct->setAttributeSetId($mapped->getAttributeSetId());
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
        }
        // url_key is deliberately never set by this importer on update -
        // Magento's own ProductUrlKeyAutogeneratorObserver
        // (catalog_product_save_before) generates it natively from
        // getName() the first time a product is saved with none set, and
        // this importer never touches an already-populated url_key
        // afterward - so an already-imported item's URL stays stable even
        // if the EC-CUBE name changes later, without this module
        // duplicating Magento's own generation/collision logic.
        //
        // On CREATE only, two cases native generation cannot handle by
        // itself, both resolved with the same UrlKeyFallbackGenerator
        // (never a new hashing implementation, never the raw EC-CUBE id
        // exposed in the URL):
        //  1. The name transliterates to '' (Japanese-only) - Product's
        //     own observer silently leaves url_key unset rather than
        //     throwing, still not a good outcome.
        //  2. The native candidate would collide with an EXISTING
        //     url_rewrite - live-confirmed this round at real dataset
        //     scale (35 collision groups, 101 items) - Magento's own
        //     save throws UrlAlreadyExistsException with no
        //     auto-resolution, confirmed against both the save-time
        //     observer and Magento's own core CSV importer. Checked via
        //     UrlKeyCollisionChecker, which queries Magento's real
        //     url_rewrite state (UrlFinderInterface, not a raw url_key
        //     comparison), so a category's hierarchy-prefixed request
        //     path is correctly never treated as a false-positive
        //     collision. The fallback input here is "item:{eccubeId}",
        //     not the name (a hash of the NAME would collide identically
        //     for two records that already share that name - the whole
        //     problem being solved), and the fallback itself is also
        //     checked, so it can never silently collide either.
        if (!$isUpdate) {
            $nativeUrlKey = $magentoProduct->formatUrlKey($mapped->getName());

            if ($nativeUrlKey === '' || $this->urlKeyCollisionChecker->wouldCollide($nativeUrlKey)) {
                $fallbackUrlKey = $this->urlKeyFallbackGenerator->generate('item', 'item:' . $mapped->getEccubeItemId());

                // Astronomically unlikely (id-keyed hash, always a
                // different input per record) but checked anyway per
                // explicit instruction, rather than assumed safe. Not
                // "invented" as a new strategy if it ever does happen -
                // logged clearly, then left to Magento's own native save
                // to reject it exactly like any other collision, so it
                // surfaces as a normal, reviewable STATUS_ERROR map row
                // instead of a silent wrong URL.
                if ($this->urlKeyCollisionChecker->wouldCollide($fallbackUrlKey)) {
                    $this->logger->error(sprintf(
                        'Item id=%d: even the deterministic fallback url_key "%s" collides with an existing url_rewrite - leaving as-is so the save fails naturally and is recorded for review.',
                        $mapped->getEccubeItemId(),
                        $fallbackUrlKey
                    ));
                }

                $magentoProduct->setUrlKey($fallbackUrlKey);
            }
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
