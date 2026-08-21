<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Repository;

use Cosmotec\EccubeMigration\Api\CategoryRepositoryInterface;
use Cosmotec\EccubeMigration\Api\Data\CategoryInterface;
use Cosmotec\EccubeMigration\Model\DTO\Category;

class CategoryRepository extends AbstractEccubeRepository implements CategoryRepositoryInterface
{
    private const TABLE = 'dtb_category';
    private const TABLE_CATEGORY_ITEM = 'dtb_category_item';

    public function getById(int $id): ?CategoryInterface
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
            'SELECT * FROM ' . self::TABLE . '
             ORDER BY hierarchy ASC, id ASC
             LIMIT ' . max(0, $limit) . ' OFFSET ' . max(0, $offset)
        );

        return array_map($this->hydrate(...), $rows);
    }

    public function countAll(): int
    {
        return (int) $this->connection->fetchScalar('SELECT COUNT(*) FROM ' . self::TABLE);
    }

    public function getModifiedSince(\DateTimeInterface $since, int $offset, int $limit): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT * FROM ' . self::TABLE . '
             WHERE update_date >= :since
             ORDER BY update_date ASC, id ASC
             LIMIT ' . max(0, $limit) . ' OFFSET ' . max(0, $offset),
            ['since' => $since->format('Y-m-d H:i:s')]
        );

        return array_map($this->hydrate(...), $rows);
    }

    public function getItemIdsByCategoryId(int $categoryId): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT item_id FROM ' . self::TABLE_CATEGORY_ITEM . '
             WHERE category_id = :category_id
             ORDER BY sort_no ASC',
            ['category_id' => $categoryId]
        );

        return array_map(static fn (array $row): int => (int) $row['item_id'], $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): CategoryInterface
    {
        return new Category(
            (int) $row['id'],
            $this->toNullableInt($row, 'parent_category_id'),
            (string) $row['category_name'],
            (string) $row['category_name_en'],
            (string) $row['short_name'],
            (string) $row['short_name_en'],
            $this->toNullableString($row, 'description'),
            $this->toNullableString($row, 'description_en'),
            (int) $row['hierarchy'],
            (int) $row['sort_no'],
            $this->toDateTimeImmutable($row, 'create_date'),
            $this->toDateTimeImmutable($row, 'update_date')
        );
    }
}
