<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Repository;

use Cosmotec\EccubeMigration\Api\Data\ProductInterface;
use Cosmotec\EccubeMigration\Api\ProductRepositoryInterface;
use Cosmotec\EccubeMigration\Model\DTO\Product;

class ProductRepository extends AbstractEccubeRepository implements ProductRepositoryInterface
{
    private const TABLE = 'dtb_product';

    public function getById(int $id): ?ProductInterface
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

    public function getByItemId(int $itemId): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT * FROM ' . self::TABLE . ' WHERE item_id = :item_id ORDER BY sort_no ASC, id ASC',
            ['item_id' => $itemId]
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

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): ProductInterface
    {
        return new Product(
            (int) $row['id'],
            $this->toNullableInt($row, 'item_id'),
            (string) $row['name'],
            (string) $row['name_en'],
            (string) $row['short_name'],
            (string) $row['short_name_en'],
            $this->toNullableString($row, 'product_code'),
            (string) $row['model'],
            $this->toNullableString($row, 'maker_part_number'),
            $this->toNullableString($row, 'note'),
            $this->toNullableString($row, 'description_list'),
            $this->toNullableString($row, 'description_detail'),
            $this->toNullableString($row, 'search_word'),
            $this->toNullableString($row, 'free_area'),
            $this->toNullableString($row, 'price'),
            $this->toNullableInt($row, 'stock_quantity'),
            $this->toBool($row, 'stock_limited_only'),
            $this->toBool($row, 'cad_unavailable_check'),
            $this->toBool($row, 'price_expiration_check'),
            $this->toNullableDateTimeImmutable($row, 'price_expiration_date'),
            (int) $row['price_expiration_judgment'],
            $this->toNullableInt($row, 'minimum_sales_quantity'),
            (int) $row['sort_no'],
            $this->toNullableInt($row, 'product_status_id'),
            $this->toNullableInt($row, 'display_status_id'),
            $this->toNullableInt($row, 'sale_type_id'),
            $this->toDateTimeImmutable($row, 'create_date'),
            $this->toDateTimeImmutable($row, 'update_date')
        );
    }
}
