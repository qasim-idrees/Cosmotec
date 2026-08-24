<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Import;

use Cosmotec\EccubeMigration\Api\CategoryMapRepositoryInterface;
use Cosmotec\EccubeMigration\Api\Data\CategoryInterface as EccubeCategoryInterface;
use Cosmotec\EccubeMigration\Api\SyncHistoryRepositoryInterface;
use Cosmotec\EccubeMigration\Logger\ImportLogger;
use Cosmotec\EccubeMigration\Model\CategoryMap;
use Cosmotec\EccubeMigration\Model\CategoryMapFactory;
use Cosmotec\EccubeMigration\Model\DTO\MagentoCategory;
use Cosmotec\EccubeMigration\Model\Mapper\CategoryMapper;
use Cosmotec\EccubeMigration\Model\Mapper\Exception\UnresolvedParentException;
use Cosmotec\EccubeMigration\Model\Reader\CategoryReader;
use Cosmotec\EccubeMigration\Model\SyncHistory;
use Cosmotec\EccubeMigration\Model\UrlKey\UrlKeyFallbackGenerator;
use Cosmotec\EccubeMigration\Model\Validator\CategoryValidator;
use Magento\Catalog\Api\CategoryRepositoryInterface as MagentoCategoryRepositoryInterface;
use Magento\Catalog\Api\Data\CategoryInterfaceFactory as MagentoCategoryFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Milestone 1 (Categories) of the import pipeline. Resume is implemented as
 * always-on idempotent skip-by-mapping-status rather than an offset
 * checkpoint: every record is checked against eccube_category_map first, so
 * a --resume run and a plain re-run behave identically and safely
 * (already-imported categories are always skipped, never duplicated).
 * --resume exists primarily so the CLI can report "resuming" honestly and
 * is reserved for future offset-based optimizations if full-table
 * re-scanning ever becomes too slow.
 */
class CategoryImporter implements ImporterInterface
{
    public function __construct(
        private readonly CategoryReader $reader,
        private readonly CategoryValidator $validator,
        private readonly CategoryMapper $mapper,
        protected readonly CategoryMapRepositoryInterface $categoryMapRepository,
        private readonly CategoryMapFactory $categoryMapFactory,
        private readonly SyncHistoryRepositoryInterface $syncHistoryRepository,
        private readonly MagentoCategoryRepositoryInterface $magentoCategoryRepository,
        private readonly MagentoCategoryFactory $magentoCategoryFactory,
        private readonly UrlKeyFallbackGenerator $urlKeyFallbackGenerator,
        protected readonly ImportLogger $logger
    ) {
    }

    public function import(ImportContext $context): ImportResult
    {
        $result = new ImportResult();

        foreach ($this->reader->read(0, $context->getBatchSize()) as $source) {
            /** @var EccubeCategoryInterface $source */
            $this->importOne($source, $context, $result);
        }

        $this->logger->info(sprintf(
            'CategoryImporter run %s complete: imported=%d updated=%d skipped=%d errors=%d',
            $context->getRunId(),
            $result->getImported(),
            $result->getUpdated(),
            $result->getSkipped(),
            $result->getErrors()
        ));

        return $result;
    }

    protected function importOne(EccubeCategoryInterface $source, ImportContext $context, ImportResult $result): void
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);

        try {
            $validation = $this->validator->validate($source);

            if (!$validation->isValid()) {
                $this->handleError($source, $context, $result, $validation->getErrorsAsString(), $startTime, $startMemory);

                return;
            }

            $existingMap = $this->categoryMapRepository->getByEccubeCategoryId($source->getId());
            $mapped = $this->mapper->map($source);

            // Gated on BOTH status and content hash - a plain status check
            // (the previous behavior) meant any category that ever reached
            // IMPORTED/UPDATED could never be touched again by either
            // import:categories or sync:categories, no matter what changed
            // in EC-CUBE source afterward - contradicting CategorySync's
            // own stated purpose of refreshing fields for records whose
            // update_date moved. Same fix class as the hash-gated skip
            // already used correctly elsewhere in this module (e.g.
            // ItemAttributeValueImporter).
            if ($this->isAlreadyDone($existingMap) && $existingMap->getContentHash() === $mapped->getContentHash()) {
                $result->incrementSkipped();
                $this->logger->info(sprintf('Category id=%d already imported and unchanged, skipping.', $source->getId()));
                $this->recordHistory($context, $source->getId(), $existingMap?->getMagentoCategoryId() !== null ? (int) $existingMap->getMagentoCategoryId() : null, SyncHistory::STATUS_SKIPPED, 'Already imported and unchanged', $startTime, $startMemory);

                return;
            }

            if ($context->isDryRun()) {
                $result->incrementImported();
                $this->logger->info(sprintf(
                    '[DRY RUN] Would %s Magento category "%s" (EC-CUBE id=%d, parent Magento id=%d)',
                    $existingMap?->getMagentoCategoryId() !== null ? 'update' : 'create',
                    $mapped->getName(),
                    $source->getId(),
                    $mapped->getMagentoParentId()
                ));

                return;
            }

            $this->persist($mapped, $existingMap, $context, $result, $startTime, $startMemory);
        } catch (UnresolvedParentException | LocalizedException | \Throwable $e) {
            $this->handleError($source, $context, $result, $e->getMessage(), $startTime, $startMemory);
        }
    }

    private function isAlreadyDone(?CategoryMap $map): bool
    {
        if ($map === null || $map->getMagentoCategoryId() === null) {
            return false;
        }

        return in_array($map->getStatus(), [CategoryMap::STATUS_IMPORTED, CategoryMap::STATUS_UPDATED], true);
    }

    private function persist(
        MagentoCategory $mapped,
        ?CategoryMap $existingMap,
        ImportContext $context,
        ImportResult $result,
        float $startTime,
        int $startMemory
    ): void {
        $isUpdate = $existingMap !== null && $existingMap->getMagentoCategoryId() !== null;

        if ($isUpdate) {
            /** @var int $magentoCategoryId */
            $magentoCategoryId = (int) $existingMap->getMagentoCategoryId();

            try {
                // Explicit store_id=0 (global scope) - same fix already
                // applied to ItemImporter/ProductImporter::persist(): without
                // it, both this load and save() below ambiently resolve to
                // whatever store StoreManager::getStore() returns in CLI
                // context, live-confirmed here too (the category-name-source
                // fix's re-import wrote the new name to store_id=1 only,
                // leaving the store_id=0/global row stale).
                $magentoCategory = $this->magentoCategoryRepository->get($magentoCategoryId, 0);
            } catch (NoSuchEntityException) {
                // Mapping pointed at a category that no longer exists in Magento
                // (e.g. manually deleted). Fall back to creating a new one.
                $magentoCategory = $this->magentoCategoryFactory->create();
                $isUpdate = false;
            }
        } else {
            $magentoCategory = $this->magentoCategoryFactory->create();
        }

        // Category::setStoreId() (unlike Product) also propagates to the
        // EAV resource model's own internal _storeId (getResource()->
        // setStoreId()) - a plain setData('store_id', 0) only updates the
        // model's data bag, which the resource model's save path does NOT
        // consult; it falls back to StoreManager::getStore()->getId()
        // (ambient CLI context) instead, live-confirmed to silently write
        // every value to store_id=1 rather than global scope.
        $magentoCategory->setStoreId(0);
        $magentoCategory->setName($mapped->getName());
        $magentoCategory->setParentId($mapped->getMagentoParentId());
        $magentoCategory->setIsActive($mapped->isActive());
        $magentoCategory->setIncludeInMenu($mapped->isIncludeInMenu());
        $magentoCategory->setPosition($mapped->getPosition());

        // Always set (never gated on !== null) so a source description that
        // becomes empty correctly clears the stale Magento value - setting
        // only when non-null (the previous behavior) left old content
        // permanently stuck on update, since the resolved value is only
        // ever recomputed here. Same "stale value not cleared" bug class as
        // ItemAttributeValueImporter/ProductAttributeValueImporter, fixed
        // the same way: setData() directly rather than setCustomAttribute(),
        // which is what those two importers' clearing logic uses.
        $magentoCategory->setData('description', $mapped->getDescription());

        // url_key is deliberately never set by this importer on update -
        // Magento's own CategoryUrlPathAutogeneratorObserver
        // (catalog_category_save_before) generates it natively from
        // getName() the first time a category is saved with none set, and
        // this importer never touches an already-populated url_key
        // afterward - so an already-imported category's URL stays stable
        // even if the EC-CUBE name changes later, without this module
        // duplicating Magento's own generation/collision logic.
        //
        // On CREATE only, one edge case native generation cannot handle
        // itself: a name that transliterates to '' (e.g. Japanese-only -
        // live-confirmed against 2 real categories this session), which
        // makes the native observer throw rather than accept an empty
        // key. UrlKeyFallbackGenerator supplies a deterministic,
        // EC-CUBE-id-free fallback for exactly that case only - see its
        // own docblock. Every other category still gets a pure Magento-
        // native url_key, untouched by this module.
        if (!$isUpdate && $magentoCategory->formatUrlKey($mapped->getName()) === '') {
            $magentoCategory->setUrlKey($this->urlKeyFallbackGenerator->generate('category', $mapped->getName()));
        }

        // CategoryRepositoryInterface::save() (unlike ProductRepository)
        // ignores the store scope set on the model entirely - it hardcodes
        // $storeId = StoreManager::getStore()->getId() (ambient CLI
        // context), then internally re-loads a FRESH category instance at
        // that ambient store and applies the passed-in data onto that,
        // discarding whatever setStoreId(0) was called on the original
        // object (live-confirmed: setStoreId(0) alone, going through the
        // repository, still wrote to store_id=1). The model's own save()
        // delegates straight to the resource model, which the earlier
        // setStoreId(0) call already correctly primed via
        // Category::setStoreId() -> getResource()->setStoreId() - this is
        // the standard, well-documented workaround for this specific
        // CategoryRepositoryInterface limitation. Triggers the exact same
        // save observers/events (catalog_category_save_before/after) since
        // the repository's save() just calls the same resource save
        // underneath, with no other side effect in between.
        $saved = $magentoCategory->save();
        $magentoCategoryId = (int) $saved->getId();

        /** @var CategoryMap $map */
        $map = $existingMap ?? $this->categoryMapFactory->create();
        $map->setEccubeCategoryId($mapped->getEccubeCategoryId());
        $map->setMagentoCategoryId($magentoCategoryId);
        $map->setContentHash($mapped->getContentHash());
        $map->setStatus($isUpdate ? CategoryMap::STATUS_UPDATED : CategoryMap::STATUS_IMPORTED);
        $map->setErrorMessage(null);
        $map->setLastSyncedAt((new \DateTimeImmutable())->format('Y-m-d H:i:s'));
        $this->categoryMapRepository->save($map);

        if ($isUpdate) {
            $result->incrementUpdated();
        } else {
            $result->incrementImported();
        }

        $this->logger->info(sprintf(
            'Category id=%d %s as Magento category id=%d ("%s")',
            $mapped->getEccubeCategoryId(),
            $isUpdate ? 'updated' : 'imported',
            $magentoCategoryId,
            $mapped->getName()
        ));

        $this->recordHistory(
            $context,
            $mapped->getEccubeCategoryId(),
            $magentoCategoryId,
            $isUpdate ? SyncHistory::STATUS_UPDATED : SyncHistory::STATUS_IMPORTED,
            null,
            $startTime,
            $startMemory
        );
    }

    /**
     * dtb_category has no del_flg/status column (confirmed against the live
     * schema) - a row deleted at EC-CUBE source simply disappears, leaving
     * no trace CategorySync's update_date watermark can ever see. Disabling
     * (is_active=false) rather than deleting the Magento category mirrors
     * the non-destructive pattern already used for
     * RelatedProductImporter::markObsoleteForProduct() /
     * ConnectionPartImporter::markObsoleteForItem() - the category and its
     * data are preserved (still restorable, still holds any child
     * categories/products) but it stops appearing in navigation/search,
     * matching the effect of EC-CUBE's own display_status_id=2 convention
     * used elsewhere in this module. Never called from a dry run.
     *
     * @param int[] $liveEccubeCategoryIds every category id currently
     *   present in dtb_category (a full scan - the source table is only
     *   ~324 rows, cheap to read in full)
     */
    public function markObsoleteForMissingSource(array $liveEccubeCategoryIds): int
    {
        $liveIds = array_map('intval', $liveEccubeCategoryIds);
        $obsolete = 0;

        foreach ($this->categoryMapRepository->getAllSuccessful() as $map) {
            // AbstractModel::getData() returns a raw DB string, not an int -
            // same recurring bug class documented elsewhere in this module.
            if (in_array((int) $map->getEccubeCategoryId(), $liveIds, true)) {
                continue;
            }

            $magentoCategoryId = $this->toIntOrNull($map->getMagentoCategoryId());

            if ($magentoCategoryId !== null) {
                try {
                    $magentoCategory = $this->magentoCategoryRepository->get($magentoCategoryId);

                    if ($magentoCategory->getIsActive()) {
                        $magentoCategory->setIsActive(false);
                        $this->magentoCategoryRepository->save($magentoCategory);
                    }
                } catch (NoSuchEntityException) {
                    // Already gone from Magento too - just flag the map row.
                }
            }

            $map->setStatus(CategoryMap::STATUS_OBSOLETE);
            $map->setErrorMessage('Category id no longer exists in dtb_category (source deletion)');
            $this->categoryMapRepository->save($map);

            $this->logger->info(sprintf(
                'Category id=%d no longer exists at EC-CUBE source - disabled Magento category id=%s',
                $map->getEccubeCategoryId(),
                $magentoCategoryId ?? 'unknown'
            ));

            $obsolete++;
        }

        return $obsolete;
    }

    private function toIntOrNull(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    private function handleError(
        EccubeCategoryInterface $source,
        ImportContext $context,
        ImportResult $result,
        string $message,
        float $startTime,
        int $startMemory
    ): void {
        $result->incrementErrors();
        $this->logger->error(sprintf('Category id=%d failed: %s', $source->getId(), $message));

        if (!$context->isDryRun()) {
            $existingMap = $this->categoryMapRepository->getByEccubeCategoryId($source->getId());
            /** @var CategoryMap $map */
            $map = $existingMap ?? $this->categoryMapFactory->create();
            $map->setEccubeCategoryId($source->getId());
            $map->setStatus(CategoryMap::STATUS_ERROR);
            $map->setErrorMessage($message);
            $this->categoryMapRepository->save($map);
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
            SyncHistory::ENTITY_TYPE_CATEGORY,
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
