<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Api;

use Cosmotec\EccubeMigration\Api\Data\ItemAdditionalContentInterface;
use Cosmotec\EccubeMigration\Model\Connection\EccubeConnectionException;

/**
 * Read-only access to dtb_item_additional_information (item-level,
 * database-driven HTML tabs).
 */
interface ItemAdditionalContentRepositoryInterface
{
    /**
     * Distinct item_ids with at least one populated tab, for batched
     * iteration - most items have none (555/3264 rows populated).
     *
     * @return int[]
     * @throws EccubeConnectionException
     */
    public function getItemIdsWithContent(int $offset, int $limit): array;

    /**
     * @return ItemAdditionalContentInterface[]
     * @throws EccubeConnectionException
     */
    public function getByItemId(int $itemId): array;
}
