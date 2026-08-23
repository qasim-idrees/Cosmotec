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
use Cosmotec\EccubeMigration\Model\UrlKey\UrlKeyCollisionChecker;
use Cosmotec\EccubeMigration\Model\UrlKey\UrlKeyFallbackGenerator;
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
        private readonly UrlKeyFallbackGenerator $urlKeyFallbackGenerator,
        private readonly UrlKeyCollisionChecker $urlKeyCollisionChecker,
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
            $mapped = $this->mapper->map($source);

            // Gated on BOTH status and content hash - a plain status check
            // (the previous behavior) meant any product that ever reached
            // IMPORTED/UPDATED/NEEDS_REVIEW could never be touched again by
            // either import:simple-products or sync:simple-products, no
            // matter what changed in EC-CUBE source afterward - the exact
            // same bug found and fixed in CategoryImporter (see
            // BUILD_STATUS.md), now confirmed to affect this importer too.
            if ($this->isAlreadyDone($existingMap) && $existingMap->getContentHash() === $mapped->getContentHash()) {
                $result->incrementSkipped();
                $this->logger->info(sprintf('Product id=%d already imported and unchanged, skipping.', $source->getId()));
                $this->recordHistory($context, $source->getId(), $existingMap?->getMagentoProductId() !== null ? (int) $existingMap->getMagentoProductId() : null, SyncHistory::STATUS_SKIPPED, 'Already imported and unchanged', $startTime, $startMemory);

                return;
            }

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

        return in_array(
            $map->getStatus(),
            [ProductMap::STATUS_IMPORTED, ProductMap::STATUS_UPDATED, ProductMap::STATUS_NEEDS_REVIEW],
            true
        );
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
                // Explicit store_id=0 (global scope) - without it, both this
                // load and the save() below ambiently resolve to whatever
                // store StoreManager::getStore() returns in this CLI
                // context (store_id=1 in this environment), the same
                // recurring bug class fixed 8+ times elsewhere this session
                // (InventoryImporter, ItemAttributeValueImporter,
                // ProductAttributeValueImporter, RelatedProductImporter,
                // MediaImporter's category-image fix). Found live this
                // round: status/name/visibility/etc. were all landing at
                // store_id=1, leaving store_id=0 (the fallback every OTHER
                // store view would read) permanently stale - harmless on
                // this single-store environment (store 1 is the only real
                // storefront and was always correct), but a genuine
                // multi-store fresh-install portability gap.
                $magentoProduct = $this->magentoProductRepository->getById((int) $existingMap->getMagentoProductId(), false, 0);
            } catch (NoSuchEntityException) {
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

        // Only ever set at creation, never on update. $mapped->
        // getAttributeSetId() is always Magento's Default set
        // (DefaultAttributeSetProvider) - ProductMapper has no way to
        // compute the real EC-CUBE-category-derived attribute set, which
        // is assigned separately and later by
        // assign:product-attribute-sets. Setting this unconditionally on
        // every persist() (the previous behavior) would silently revert an
        // already-correctly-assigned product back to Default on its next
        // update - orphaning every ecs_* value already written
        // against the real attribute set (Magento's EAV save silently
        // drops values for attributes outside the product's current
        // attribute set, with no exception - the exact "Round 31 false
        // success" failure mode this module has hit before). Found live
        // this round via a careful diff before executing the newly-fixed
        // hash-gate update at scale - would have reverted ~18,000 products'
        // attribute sets in a single --execute run.
        if (!$isUpdate) {
            $magentoProduct->setAttributeSetId($mapped->getAttributeSetId());
        }

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
            // See ItemImporter::persist() for why getWebsites() is used
            // instead of getWebsite() - the latter's ambient context
            // resolution put 19,072 of 28,277 products on website_id=0
            // ("Admin"), live-confirmed this session.
            $magentoProduct->setWebsiteIds(array_keys($this->storeManager->getWebsites()));
        }
        // url_key: never set on update - see ItemImporter::persist() for
        // the full reasoning (Magento's own ProductUrlKeyAutogeneratorObserver
        // generates it natively). On CREATE only, the same two fallback
        // cases as ItemImporter - see there for the full explanation
        // (empty transliteration: 8 real products, the "*****"-named
        // needs_review placeholders; collision: live-confirmed this round
        // at real dataset scale, 792 collision groups, 1,609 products,
        // including 360 that collide with an already-imported Item, not
        // just with each other - Simple Products and Items share the same
        // Magento entity type and request-path namespace).
        if (!$isUpdate) {
            $nativeUrlKey = $magentoProduct->formatUrlKey($mapped->getName());

            if ($nativeUrlKey === '' || $this->urlKeyCollisionChecker->wouldCollide($nativeUrlKey)) {
                $fallbackUrlKey = $this->urlKeyFallbackGenerator->generate('product', 'product:' . $mapped->getEccubeProductId());

                if ($this->urlKeyCollisionChecker->wouldCollide($fallbackUrlKey)) {
                    $this->logger->error(sprintf(
                        'Product id=%d: even the deterministic fallback url_key "%s" collides with an existing url_rewrite - leaving as-is so the save fails naturally and is recorded for review.',
                        $mapped->getEccubeProductId(),
                        $fallbackUrlKey
                    ));
                }

                $magentoProduct->setUrlKey($fallbackUrlKey);
            }
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

        if ($mapped->priceNeedsReview()) {
            $map->setStatus(ProductMap::STATUS_NEEDS_REVIEW);
        } else {
            $map->setStatus($isUpdate ? ProductMap::STATUS_UPDATED : ProductMap::STATUS_IMPORTED);
        }

        if (!$isUpdate) {
            $map->setRelationLinked(0);
        }

        $map->setErrorMessage($mapped->priceNeedsReview()
            ? 'EC-CUBE source price is NULL - imported disabled with price=0.00, needs manual pricing review'
            : null);
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
