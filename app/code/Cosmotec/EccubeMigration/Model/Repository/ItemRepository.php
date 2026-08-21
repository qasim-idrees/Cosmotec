<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Repository;

use Cosmotec\EccubeMigration\Api\Data\ItemInterface;
use Cosmotec\EccubeMigration\Api\ItemRepositoryInterface;
use Cosmotec\EccubeMigration\Model\DTO\Item;

class ItemRepository extends AbstractEccubeRepository implements ItemRepositoryInterface
{
    private const TABLE = 'dtb_item';
    private const TABLE_CATEGORY_ITEM = 'dtb_category_item';
    private const TABLE_PRODUCT = 'dtb_product';

    public function getById(int $id): ?ItemInterface
    {
        $row = $this->connection->fetchOne(
            'SELECT * FROM ' . self::TABLE . ' WHERE id = :id',
            ['id' => $id]
        );

        return $row === null ? null : $this->hydrate($row);
    }

    public function getBatch(int $offset, int $limit): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT * FROM ' . self::TABLE . ' ORDER BY id ASC LIMIT ' . max(0, $limit) . ' OFFSET ' . max(0, $offset)
        );

        return array_map($this->hydrate(...), $rows);
    }

    public function countAll(): int
    {
        return (int) $this->connection->fetchScalar('SELECT COUNT(*) FROM ' . self::TABLE);
    }

    /**
     * dtb_item carries no update_date, so "modified since" is derived from
     * MAX(dtb_product.update_date) among the item's children.
     */
    public function getModifiedSince(\DateTimeInterface $since, int $offset, int $limit): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT i.* FROM ' . self::TABLE . ' i
             INNER JOIN (
                 SELECT item_id, MAX(update_date) AS last_update
                 FROM ' . self::TABLE_PRODUCT . '
                 GROUP BY item_id
             ) p ON p.item_id = i.id
             WHERE p.last_update >= :since
             ORDER BY p.last_update ASC, i.id ASC
             LIMIT ' . max(0, $limit) . ' OFFSET ' . max(0, $offset),
            ['since' => $since->format('Y-m-d H:i:s')]
        );

        return array_map($this->hydrate(...), $rows);
    }

    public function getCategoryIdsByItemId(int $itemId): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT category_id FROM ' . self::TABLE_CATEGORY_ITEM . '
             WHERE item_id = :item_id
             ORDER BY sort_no ASC',
            ['item_id' => $itemId]
        );

        return array_map(static fn (array $row): int => (int) $row['category_id'], $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): ItemInterface
    {
        return new Item(
            (int) $row['id'],
            (string) $row['name'],
            (string) $row['name_en'],
            (string) $row['short_name'],
            (string) $row['short_name_en'],
            $this->toNullableString($row, 'description'),
            $this->toNullableString($row, 'description_en'),
            $this->toNullableInt($row, 'display_status_id')
        );
    }
}
