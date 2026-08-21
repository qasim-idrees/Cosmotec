<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Repository;

use Cosmotec\EccubeMigration\Api\SyncHistoryRepositoryInterface;
use Cosmotec\EccubeMigration\Model\ResourceModel\SyncHistory\CollectionFactory as SyncHistoryCollectionFactory;
use Cosmotec\EccubeMigration\Model\ResourceModel\SyncHistory as SyncHistoryResource;
use Cosmotec\EccubeMigration\Model\SyncHistory;
use Cosmotec\EccubeMigration\Model\SyncHistoryFactory;

class SyncHistoryRepository implements SyncHistoryRepositoryInterface
{
    public function __construct(
        private readonly SyncHistoryResource $resource,
        private readonly SyncHistoryFactory $syncHistoryFactory,
        private readonly SyncHistoryCollectionFactory $collectionFactory
    ) {
    }

    public function record(
        string $runId,
        string $entityType,
        string $operation,
        int $sourceId,
        ?int $targetId,
        string $status,
        ?string $message = null,
        ?int $durationMs = null,
        ?int $memoryBytes = null
    ): SyncHistory {
        /** @var SyncHistory $history */
        $history = $this->syncHistoryFactory->create();
        $history->setRunId($runId);
        $history->setEntityType($entityType);
        $history->setOperation($operation);
        $history->setSourceId($sourceId);
        $history->setTargetId($targetId);
        $history->setStatus($status);
        $history->setMessage($message);
        $history->setDurationMs($durationMs);
        $history->setMemoryBytes($memoryBytes);

        $this->resource->save($history);

        return $history;
    }

    public function countByRunIdAndStatus(string $runId, string $status): int
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('run_id', $runId);
        $collection->addFieldToFilter('status', $status);

        return $collection->getSize();
    }

    public function getRecent(int $limit): array
    {
        $collection = $this->collectionFactory->create();
        $collection->setOrder('entity_id', 'DESC');
        $collection->setPageSize($limit);
        $collection->setCurPage(1);

        return array_values($collection->getItems());
    }
}
