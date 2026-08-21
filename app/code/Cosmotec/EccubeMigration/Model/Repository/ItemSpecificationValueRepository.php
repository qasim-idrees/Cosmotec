<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Repository;

use Cosmotec\EccubeMigration\Api\Data\ItemSpecificationValueInterface;
use Cosmotec\EccubeMigration\Api\ItemSpecificationValueRepositoryInterface;
use Cosmotec\EccubeMigration\Model\DTO\ItemSpecificationValue;

/**
 * Read-only. Joins dtb_item_specification (the item/specification/scope/
 * ordering carrier) to dtb_item_specification_class (the value wrapper) to
 * resolve the actual chosen dtb_specification_class option - verified
 * against the real schema (dtb_item_specification_class itself has no
 * item_id or specification_id column; the link is entirely through
 * dtb_item_specification.item_specification_class_id).
 */
class ItemSpecificationValueRepository extends AbstractEccubeRepository implements ItemSpecificationValueRepositoryInterface
{
    private const TYPE_ITEM = 1;

    public function getByItemId(int $itemId): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT isp.id, isp.item_id, isp.specification_id, isp.sort_no, isp.selectable,
                    isc.specification_class_id
             FROM dtb_item_specification isp
             INNER JOIN dtb_item_specification_class isc ON isc.id = isp.item_specification_class_id
             WHERE isp.item_id = :item_id
               AND isp.type = ' . self::TYPE_ITEM . '
               AND isp.specification_id IS NOT NULL
               AND isc.specification_class_id IS NOT NULL
             ORDER BY isp.sort_no ASC, isp.id ASC',
            ['item_id' => $itemId]
        );

        return array_map(
            static fn (array $row): ItemSpecificationValueInterface => new ItemSpecificationValue(
                (int) $row['id'],
                (int) $row['item_id'],
                (int) $row['specification_id'],
                (int) $row['specification_class_id'],
                (int) $row['sort_no'],
                ((int) $row['selectable']) === 1
            ),
            $rows
        );
    }
}
