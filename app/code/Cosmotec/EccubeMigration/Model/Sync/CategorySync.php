<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Sync;

use Cosmotec\EccubeMigration\Api\CategoryMapRepositoryInterface;
use Cosmotec\EccubeMigration\Api\CategoryRepositoryInterface as EccubeCategoryRepositoryInterface;
use Cosmotec\EccubeMigration\Logger\ImportLogger;
use Cosmotec\EccubeMigration\Model\CategoryMapFactory;
use Cosmotec\EccubeMigration\Model\Import\CategoryImporter;
use Cosmotec\EccubeMigration\Model\Import\ImportContext;
use Cosmotec\EccubeMigration\Model\Import\ImportResult;
use Cosmotec\EccubeMigration\Model\Mapper\CategoryMapper;
use Cosmotec\EccubeMigration\Model\Reader\CategoryReader;
use Cosmotec\EccubeMigration\Model\UrlKey\UrlKeyFallbackGenerator;
use Cosmotec\EccubeMigration\Model\Validator\CategoryValidator;
use Magento\Catalog\Api\CategoryRepositoryInterface as MagentoCategoryRepositoryInterface;
use Magento\Catalog\Api\Data\CategoryInterfaceFactory as MagentoCategoryFactory;
use Cosmotec\EccubeMigration\Api\SyncHistoryRepositoryInterface;

/**
 * Milestone 8 (Synchronization). Extends CategoryImporter rather than
 * duplicating its validate/map/persist/error-handling logic — the ONLY
 * difference between import and sync for categories is which records get
 * scanned: import:categories does a full table scan (parent-before-child
 * order matters there because categories may not exist in Magento yet);
 * sync:categories only re-scans records with update_date at or after the
 * most recent successful import/sync (the watermark from
 * CategoryMapRepository::getMaxLastSyncedAt()), since by definition those
 * records already exist in Magento and only need their fields refreshed.
 */
class CategorySync extends CategoryImporter
{
    public function __construct(
        CategoryReader $reader,
        CategoryValidator $validator,
        CategoryMapper $mapper,
        CategoryMapRepositoryInterface $categoryMapRepository,
        CategoryMapFactory $categoryMapFactory,
        SyncHistoryRepositoryInterface $syncHistoryRepository,
        MagentoCategoryRepositoryInterface $magentoCategoryRepository,
        MagentoCategoryFactory $magentoCategoryFactory,
        UrlKeyFallbackGenerator $urlKeyFallbackGenerator,
        ImportLogger $logger,
        private readonly EccubeCategoryRepositoryInterface $eccubeCategoryRepository
    ) {
        parent::__construct(
            $reader,
            $validator,
            $mapper,
            $categoryMapRepository,
            $categoryMapFactory,
            $syncHistoryRepository,
            $magentoCategoryRepository,
            $magentoCategoryFactory,
            $urlKeyFallbackGenerator,
            $logger
        );
    }

    public function import(ImportContext $context): ImportResult
    {
        $result = new ImportResult();
        $since = $this->categoryMapRepository->getMaxLastSyncedAt() ?? new \DateTimeImmutable('@0');
        $batchSize = $context->getBatchSize() ?? 100;
        $offset = 0;

        $this->logger->info(sprintf('CategorySync run %s: scanning categories modified since %s', $context->getRunId(), $since->format('Y-m-d H:i:s')));

        while (true) {
            $page = $this->eccubeCategoryRepository->getModifiedSince($since, $offset, $batchSize);

            if ($page === []) {
                break;
            }

            foreach ($page as $source) {
                $this->importOne($source, $context, $result);
            }

            $offset += $batchSize;

            if (count($page) < $batchSize) {
                break;
            }
        }

        if (!$context->isDryRun()) {
            $obsoleteCount = $this->markObsoleteForMissingSource($this->getAllLiveEccubeCategoryIds());

            if ($obsoleteCount > 0) {
                $this->logger->info(sprintf('CategorySync run %s: disabled %d categor(y/ies) deleted at EC-CUBE source', $context->getRunId(), $obsoleteCount));
            }
        }

        $this->logger->info(sprintf(
            'CategorySync run %s complete: imported=%d updated=%d skipped=%d errors=%d',
            $context->getRunId(),
            $result->getImported(),
            $result->getUpdated(),
            $result->getSkipped(),
            $result->getErrors()
        ));

        return $result;
    }

    /**
     * dtb_category has no del_flg (confirmed against the live schema), so
     * detecting a source deletion requires a full id scan, not the
     * update_date watermark used above for ordinary changes. Only ~324
     * categories total - cheap to read in full every sync run.
     *
     * @return int[]
     */
    private function getAllLiveEccubeCategoryIds(): array
    {
        $ids = [];
        $offset = 0;
        $batchSize = 500;

        while (true) {
            $page = $this->eccubeCategoryRepository->getBatch($offset, $batchSize);

            if ($page === []) {
                break;
            }

            foreach ($page as $source) {
                $ids[] = $source->getId();
            }

            if (count($page) < $batchSize) {
                break;
            }

            $offset += $batchSize;
        }

        return $ids;
    }
}
