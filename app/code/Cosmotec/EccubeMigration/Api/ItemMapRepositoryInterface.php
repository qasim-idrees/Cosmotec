<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Api;

use Cosmotec\EccubeMigration\Model\ItemMap;
use Magento\Framework\Exception\CouldNotSaveException;

interface ItemMapRepositoryInterface
{
    public function getByEccubeItemId(int $eccubeItemId): ?ItemMap;

    /**
     * @throws CouldNotSaveException
     */
    public function save(ItemMap $itemMap): ItemMap;

    /**
     * @return ItemMap[]
     */
    public function getUnfinished(int $limit): array;

    public function countByStatus(string $status): int;

    /**
     * Timestamp of the most recent successful import/sync, used by
     * ItemSync as the watermark for "what's changed since we last looked".
     */
    public function getMaxLastSyncedAt(): ?\DateTimeImmutable;
}
