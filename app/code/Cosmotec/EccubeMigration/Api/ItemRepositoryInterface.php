<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Api;

use Cosmotec\EccubeMigration\Api\Data\ItemInterface;
use Cosmotec\EccubeMigration\Model\Connection\EccubeConnectionException;

interface ItemRepositoryInterface
{
    /**
     * @throws EccubeConnectionException
     */
    public function getById(int $id): ?ItemInterface;

    /**
     * @return ItemInterface[]
     * @throws EccubeConnectionException
     */
    public function getBatch(int $offset, int $limit): array;

    /**
     * @throws EccubeConnectionException
     */
    public function countAll(): int;

    /**
     * dtb_item has no update_date column. "Modified since" is derived from
     * the most recent update_date among the item's child dtb_product rows.
     *
     * @return ItemInterface[]
     * @throws EccubeConnectionException
     */
    public function getModifiedSince(\DateTimeInterface $since, int $offset, int $limit): array;

    /**
     * Category IDs attached to this item via dtb_category_item.
     *
     * @return int[]
     * @throws EccubeConnectionException
     */
    public function getCategoryIdsByItemId(int $itemId): array;
}
