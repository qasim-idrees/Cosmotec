<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Repository;

use Cosmotec\EccubeMigration\Api\CouplingProductRepositoryInterface;
use Cosmotec\EccubeMigration\Api\Data\CouplingProductInterface;
use Cosmotec\EccubeMigration\Model\DTO\CouplingProduct;

/**
 * Read-only. Schema verified live via DESCRIBE: id, item_id, product_id,
 * discriminator_type - no ordering column, so id ASC is the deterministic
 * fallback (same pattern as ProductReferenceRepository).
 */
class CouplingProductRepository extends AbstractEccubeRepository implements CouplingProductRepositoryInterface
{
    private const TABLE = 'dtb_coupling_product';

    public function getBatch(int $offset, int $limit): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT id, item_id, product_id FROM ' . self::TABLE . '
             WHERE item_id IS NOT NULL
             ORDER BY item_id ASC, id ASC
             LIMIT ' . max(0, $limit) . ' OFFSET ' . max(0, $offset)
        );

        return array_map($this->hydrate(...), $rows);
    }

    public function getByItemId(int $itemId): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT id, item_id, product_id FROM ' . self::TABLE . '
             WHERE item_id = :item_id
             ORDER BY id ASC',
            ['item_id' => $itemId]
        );

        return array_map($this->hydrate(...), $rows);
    }

    public function countAll(): int
    {
        return (int) $this->connection->fetchScalar(
            'SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE item_id IS NOT NULL'
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): CouplingProductInterface
    {
        return new CouplingProduct(
            (int) $row['id'],
            (int) $row['item_id'],
            (int) $row['product_id']
        );
    }
}
