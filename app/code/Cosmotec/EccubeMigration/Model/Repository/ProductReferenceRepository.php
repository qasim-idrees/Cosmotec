<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Repository;

use Cosmotec\EccubeMigration\Api\Data\ProductReferenceInterface;
use Cosmotec\EccubeMigration\Api\ProductReferenceRepositoryInterface;
use Cosmotec\EccubeMigration\Model\DTO\ProductReference;

/**
 * Read-only.
 *
 * ORDERING: verified against the production schema - dtb_product_reference
 * has NO sort_no or equivalent ordering column (id, product_id,
 * creator_id, name, link, create_date, update_date, discriminator_type).
 * Source id order is therefore used as a deterministic fallback, and is
 * documented as such rather than presented as real source ordering.
 *
 * There is no two-reference limit at the data layer: the admin form caps
 * input at two, but the table is a genuine 1:N relation (25,586 rows) and
 * every row is migrated.
 */
class ProductReferenceRepository extends AbstractEccubeRepository implements ProductReferenceRepositoryInterface
{
    private const TABLE = 'dtb_product_reference';

    public function getBatch(int $offset, int $limit): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT id, product_id, name, link FROM ' . self::TABLE . '
             WHERE product_id IS NOT NULL
             ORDER BY product_id ASC, id ASC
             LIMIT ' . max(0, $limit) . ' OFFSET ' . max(0, $offset)
        );

        return array_map($this->hydrate(...), $rows);
    }

    public function getByProductId(int $productId): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT id, product_id, name, link FROM ' . self::TABLE . '
             WHERE product_id = :product_id
             ORDER BY id ASC',
            ['product_id' => $productId]
        );

        return array_map($this->hydrate(...), $rows);
    }

    public function countAll(): int
    {
        return (int) $this->connection->fetchScalar(
            'SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE product_id IS NOT NULL'
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): ProductReferenceInterface
    {
        return new ProductReference(
            (int) $row['id'],
            (int) $row['product_id'],
            $this->toNullableString($row, 'name'),
            $this->toNullableString($row, 'link')
        );
    }
}
