<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Repository;

use Cosmotec\EccubeMigration\Api\Data\ProductSpecificationValueInterface;
use Cosmotec\EccubeMigration\Api\ProductSpecificationValueRepositoryInterface;
use Cosmotec\EccubeMigration\Model\DTO\ProductSpecificationValue;

/**
 * Read-only. dtb_product_specification_class has no specification_id of
 * its own (verified live via DESCRIBE) - every query here joins through
 * dtb_specification_class to resolve it.
 */
class ProductSpecificationValueRepository extends AbstractEccubeRepository implements ProductSpecificationValueRepositoryInterface
{
    public function getByProductId(int $productId): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT pspc.id, pspc.product_id, pspc.specification_class_id, sc.specification_id
             FROM dtb_product_specification_class pspc
             INNER JOIN dtb_specification_class sc ON sc.id = pspc.specification_class_id
             WHERE pspc.product_id = :product_id
             ORDER BY sc.specification_id ASC, pspc.id ASC',
            ['product_id' => $productId]
        );

        return array_map(
            static fn (array $row): ProductSpecificationValueInterface => new ProductSpecificationValue(
                (int) $row['id'],
                (int) $row['product_id'],
                (int) $row['specification_id'],
                (int) $row['specification_class_id']
            ),
            $rows
        );
    }

    public function getProductIdsWithValues(int $offset, int $limit): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT DISTINCT product_id
             FROM dtb_product_specification_class
             WHERE product_id IS NOT NULL
             ORDER BY product_id ASC
             LIMIT ' . max(0, $limit) . ' OFFSET ' . max(0, $offset)
        );

        return array_map(static fn (array $row): int => (int) $row['product_id'], $rows);
    }

    public function countProductsWithValues(): int
    {
        return (int) $this->connection->fetchScalar(
            'SELECT COUNT(DISTINCT product_id) FROM dtb_product_specification_class WHERE product_id IS NOT NULL'
        );
    }
}
