<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Repository;

use Cosmotec\EccubeMigration\Api\Data\RelatedProductInterface;
use Cosmotec\EccubeMigration\Api\RelatedProductRepositoryInterface;
use Cosmotec\EccubeMigration\Model\DTO\RelatedProduct;

/**
 * Read-only. Schema verified live via DESCRIBE: id, product_id,
 * discriminator_type, related_product_id - no ordering column, so id ASC
 * is the deterministic fallback (same pattern as ProductReferenceRepository
 * and CouplingProductRepository).
 */
class RelatedProductRepository extends AbstractEccubeRepository implements RelatedProductRepositoryInterface
{
    private const TABLE = 'dtb_related_product';

    public function getBatch(int $offset, int $limit): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT id, product_id, related_product_id FROM ' . self::TABLE . '
             WHERE product_id IS NOT NULL AND related_product_id IS NOT NULL
             ORDER BY product_id ASC, id ASC
             LIMIT ' . max(0, $limit) . ' OFFSET ' . max(0, $offset)
        );

        return array_map($this->hydrate(...), $rows);
    }

    public function getByProductId(int $productId): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT id, product_id, related_product_id FROM ' . self::TABLE . '
             WHERE product_id = :product_id AND related_product_id IS NOT NULL
             ORDER BY id ASC',
            ['product_id' => $productId]
        );

        return array_map($this->hydrate(...), $rows);
    }

    public function countAll(): int
    {
        return (int) $this->connection->fetchScalar(
            'SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE product_id IS NOT NULL AND related_product_id IS NOT NULL'
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): RelatedProductInterface
    {
        return new RelatedProduct(
            (int) $row['id'],
            (int) $row['product_id'],
            (int) $row['related_product_id']
        );
    }
}
