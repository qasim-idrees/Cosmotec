<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Sync;

use Cosmotec\EccubeMigration\Api\MediaMapRepositoryInterface;
use Cosmotec\EccubeMigration\Api\MediaRepositoryInterface;
use Cosmotec\EccubeMigration\Logger\SyncLogger;
use Cosmotec\EccubeMigration\Model\Import\ImportContext;
use Cosmotec\EccubeMigration\Model\Import\ImporterInterface;
use Cosmotec\EccubeMigration\Model\Import\ImportResult;
use Cosmotec\EccubeMigration\Model\Import\MediaImporter;
use Cosmotec\EccubeMigration\Model\Media\MediaRelationType;
use Cosmotec\EccubeMigration\Model\MediaMap;

/**
 * Relation-aware media synchronization.
 *
 * MediaImporter already handles CREATE / UPDATE / SKIP idempotently via
 * content hashes, so sync reuses it and adds the one thing import cannot
 * infer: source relations that have DISAPPEARED. Those are marked
 * obsolete rather than deleted, and never when another active relation
 * still points at the same physical file.
 */
class MediaSync implements ImporterInterface
{
    private const OWNER_PAGE_SIZE = 500;

    public function __construct(
        private readonly MediaImporter $mediaImporter,
        private readonly MediaRepositoryInterface $mediaRepository,
        private readonly MediaMapRepositoryInterface $mediaMapRepository,
        private readonly SyncLogger $logger
    ) {
    }

    public function import(ImportContext $context): ImportResult
    {
        $result = new ImportResult();

        foreach (MediaRelationType::all() as $relationType) {
            $this->mediaImporter->importRelation($relationType, $context, $result);
            $this->markObsolete($relationType, $context, $result);
        }

        $this->logger->info(sprintf(
            'MediaSync run %s complete: imported=%d updated=%d skipped=%d errors=%d',
            $context->getRunId(),
            $result->getImported(),
            $result->getUpdated(),
            $result->getSkipped(),
            $result->getErrors()
        ));

        return $result;
    }

    /**
     * Marks mappings obsolete when the source relation is gone.
     *
     * Processes one owner at a time: owner ids are paged from the source,
     * and for each owner only that owner's live file ids and existing
     * mappings are held in memory. Nothing proportional to the full
     * 71,072-relation inventory is ever materialised.
     *
     * The physical Magento file is retained if any other active mapping
     * still references the same dtb_upload_file.
     */
    private function markObsolete(MediaRelationType $relationType, ImportContext $context, ImportResult $result): void
    {
        $offset = 0;

        while (true) {
            $ownerIds = $this->mediaRepository->getOwnerIds($relationType, $offset, self::OWNER_PAGE_SIZE);

            if ($ownerIds === []) {
                break;
            }

            foreach ($ownerIds as $ownerId) {
                $this->markObsoleteForOwner($relationType, $ownerId, $context, $result);
            }

            if (count($ownerIds) < self::OWNER_PAGE_SIZE) {
                break;
            }

            $offset += self::OWNER_PAGE_SIZE;
        }
    }

    private function markObsoleteForOwner(
        MediaRelationType $relationType,
        int $ownerId,
        ImportContext $context,
        ImportResult $result
    ): void {
        $existingMaps = $this->mediaMapRepository->getByOwner($relationType, $ownerId);

        if ($existingMaps === []) {
            return;
        }

        $liveFileIds = array_map(
            static fn ($file): int => $file->getUploadFileId(),
            $this->mediaRepository->getByOwner($relationType, $ownerId)
        );

        foreach ($existingMaps as $map) {
            // AbstractModel::getData() returns a raw DB string, not an int
            // (despite the getter's phpdoc) - without this cast, every row
            // would fail the strict in_array() check against $liveFileIds
            // (real ints) and be wrongly marked obsolete even though its
            // source file still exists. Same bug class fixed in Round 42
            // for RelatedProductImporter/ConnectionPartImporter/
            // ProductReferenceImporter - found here too via a broader
            // search prompted by the Round 46 ProductRelationImporter
            // TypeError.
            if (in_array((int) $map->getEccubeUploadFileId(), $liveFileIds, true)) {
                continue;
            }

            if ($map->getStatus() === MediaMap::STATUS_OBSOLETE) {
                continue;
            }

            $stillUsed = $this->mediaMapRepository->getOtherActiveUsages(
                $map->getEccubeUploadFileId(),
                (int) $map->getId()
            );

            $map->setStatus(MediaMap::STATUS_OBSOLETE);
            $map->setErrorMessage(
                $stillUsed === []
                    ? 'Source relation removed; physical file no longer referenced'
                    : 'Source relation removed; physical file retained (still used by another relation)'
            );

            if (!$context->isDryRun()) {
                $this->mediaMapRepository->save($map);
            }

            $this->logger->info(sprintf(
                'MediaSync[%s]: marked upload file %d (owner %d) obsolete',
                $relationType->value,
                $map->getEccubeUploadFileId(),
                $ownerId
            ));

            $result->incrementSkipped();
        }
    }
}
