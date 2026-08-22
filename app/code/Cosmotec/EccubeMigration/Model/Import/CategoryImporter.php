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

            if ($this->isAlreadyDone($existingMap)) {
                $result->incrementSkipped();
                $this->logger->info(sprintf('Category id=%d already imported, skipping.', $source->getId()));
                $this->recordHistory($context, $source->getId(), $existingMap?->getMagentoCategoryId() !== null ? (int) $existingMap->getMagentoCategoryId() : null, SyncHistory::STATUS_SKIPPED, 'Already imported', $startTime, $startMemory);

                return;
            }

            $mapped = $this->mapper->map($source);

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
                $magentoCategory = $this->magentoCategoryRepository->get($magentoCategoryId);
            } catch (NoSuchEntityException) {
                // Mapping pointed at a category that no longer exists in Magento
                // (e.g. manually deleted). Fall back to creating a new one.
                $magentoCategory = $this->magentoCategoryFactory->create();
                $isUpdate = false;
            }
        } else {
            $magentoCategory = $this->magentoCategoryFactory->create();
        }

        $magentoCategory->setName($mapped->getName());
        $magentoCategory->setParentId($mapped->getMagentoParentId());
        $magentoCategory->setIsActive($mapped->isActive());
        $magentoCategory->setIncludeInMenu($mapped->isIncludeInMenu());
        $magentoCategory->setPosition($mapped->getPosition());

        if ($mapped->getDescription() !== null) {
            $magentoCategory->setCustomAttribute('description', $mapped->getDescription());
        }

        if ($mapped->getUrlKey() !== null) {
            $magentoCategory->setCustomAttribute('url_key', $mapped->getUrlKey());
        }

        $saved = $this->magentoCategoryRepository->save($magentoCategory);
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
