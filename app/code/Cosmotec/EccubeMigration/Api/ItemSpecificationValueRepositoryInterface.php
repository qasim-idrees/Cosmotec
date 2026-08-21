<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Api;

use Cosmotec\EccubeMigration\Api\Data\ItemSpecificationValueInterface;
use Cosmotec\EccubeMigration\Model\Connection\EccubeConnectionException;

/**
 * Read-only. All SQL for ITEM-scope (parent) specification values lives
 * here.
 */
interface ItemSpecificationValueRepositoryInterface
{
    /**
     * All type=1 (ITEM scope) specification values for one item, resolved
     * through the item_specification_class -> specification_class join.
     * Ordered by the source's own sort_no, then id as a stable tie-break.
     *
     * @return ItemSpecificationValueInterface[]
     * @throws EccubeConnectionException
     */
    public function getByItemId(int $itemId): array;
}
