<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Api;

use Cosmotec\EccubeMigration\Model\SyncHistory;

/**
 * Persistence for eccube_sync_history — one row per record processed by any
 * import or sync command, across all entity types.
 */
interface SyncHistoryRepositoryInterface
{
    /**
     * Convenience factory + save in one call, used by every Importer/Sync
     * class so they don't each re-implement row construction.
     */
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
    ): SyncHistory;

    public function countByRunIdAndStatus(string $runId, string $status): int;

    /**
     * Most recent entries across all entity types, newest first. Used by
     * the admin Dashboard's recent-activity feed.
     *
     * @return SyncHistory[]
     */
    public function getRecent(int $limit): array;
}
