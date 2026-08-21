<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Sync;

use Cosmotec\EccubeMigration\Api\CouplingProductMapRepositoryInterface;
use Cosmotec\EccubeMigration\Logger\SyncLogger;
use Cosmotec\EccubeMigration\Model\Import\ConnectionPartImporter;
use Cosmotec\EccubeMigration\Model\Import\ImportContext;
use Cosmotec\EccubeMigration\Model\Import\ImporterInterface;
use Cosmotec\EccubeMigration\Model\Import\ImportResult;

/**
 * Same pattern as RelatedProductSync/MediaSync: ConnectionPartImporter
 * already handles CREATE/SKIP idempotently; sync adds obsolete-detection
 * via ConnectionPartImporter::markObsoleteForItem() for coupling rows
 * removed at source.
 */
class ConnectionPartSync implements ImporterInterface
{
    private const ITEM_PAGE_SIZE = 500;

    public function __construct(
        private readonly ConnectionPartImporter $connectionPartImporter,
        private readonly CouplingProductMapRepositoryInterface $mapRepository,
        private readonly SyncLogger $logger
    ) {
    }

    public function import(ImportContext $context): ImportResult
    {
        $result = $this->connectionPartImporter->import($context);
        $this->markObsolete($context);

        $this->logger->info(sprintf(
            'ConnectionPartSync run %s complete: imported=%d updated=%d skipped=%d errors=%d',
            $context->getRunId(),
            $result->getImported(),
            $result->getUpdated(),
            $result->getSkipped(),
            $result->getErrors()
        ));

        return $result;
    }

    private function markObsolete(ImportContext $context): void
    {
        if ($context->isDryRun()) {
            return;
        }

        $offset = 0;

        while (true) {
            $itemIds = $this->mapRepository->getDistinctItemIds($offset, self::ITEM_PAGE_SIZE);

            if ($itemIds === []) {
                break;
            }

            foreach ($itemIds as $itemId) {
                $count = $this->connectionPartImporter->markObsoleteForItem($itemId);

                if ($count > 0) {
                    $this->logger->info(sprintf('ConnectionPartSync: marked %d coupling(s) obsolete for item id=%d', $count, $itemId));
                }
            }

            if (count($itemIds) < self::ITEM_PAGE_SIZE) {
                break;
            }

            $offset += self::ITEM_PAGE_SIZE;
        }
    }
}
