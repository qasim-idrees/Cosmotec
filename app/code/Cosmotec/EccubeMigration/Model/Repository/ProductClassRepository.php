<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Repository;

use Cosmotec\EccubeMigration\Api\Data\ProductClassInterface;
use Cosmotec\EccubeMigration\Api\ProductClassRepositoryInterface;
use Cosmotec\EccubeMigration\Model\DTO\ProductClass;

class ProductClassRepository extends AbstractEccubeRepository implements ProductClassRepositoryInterface
{
    private const TABLE = 'dtb_product_class';

    public function getByProductId(int $productId): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT * FROM ' . self::TABLE . ' WHERE product_id = :product_id ORDER BY id ASC',
            ['product_id' => $productId]
        );

        return array_map($this->hydrate(...), $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): ProductClassInterface
    {
        return new ProductClass(
            (int) $row['id'],
            $this->toNullableInt($row, 'product_id'),
            $this->toNullableString($row, 'product_code'),
            $this->toNullableString($row, 'stock'),
            $this->toBool($row, 'stock_unlimited'),
            $this->toNullableString($row, 'price01'),
            (string) $row['price02'],
            $this->toNullableString($row, 'delivery_fee'),
            $this->toBool($row, 'visible'),
            $this->toNullableString($row, 'currency_code'),
            $this->toNullableInt($row, 'sale_type_id'),
            $this->toNullableInt($row, 'class_category_id1'),
            $this->toNullableInt($row, 'class_category_id2')
        );
    }
}
