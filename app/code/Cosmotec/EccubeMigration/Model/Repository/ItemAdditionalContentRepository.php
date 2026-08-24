<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Repository;

use Cosmotec\EccubeMigration\Api\Data\ItemAdditionalContentInterface;
use Cosmotec\EccubeMigration\Api\ItemAdditionalContentRepositoryInterface;
use Cosmotec\EccubeMigration\Model\DTO\ItemAdditionalContent;

/**
 * Read-only.
 *
 * ORDERING: dtb_item_additional_information has no sort_no or equivalent
 * ordering column (id, item_id, name, value, discriminator_type,
 * name_en) - source id order is used as a deterministic fallback, same
 * pattern as ProductReferenceRepository.
 *
 * Only rows with a non-empty value are real tabs (555 of 3264 rows,
 * source-confirmed) - the remaining rows are empty placeholders with no
 * content to migrate.
 */
class ItemAdditionalContentRepository extends AbstractEccubeRepository implements ItemAdditionalContentRepositoryInterface
{
    private const TABLE = 'dtb_item_additional_information';

    public function getItemIdsWithContent(int $offset, int $limit): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT DISTINCT item_id FROM ' . self::TABLE . '
             WHERE item_id IS NOT NULL AND value IS NOT NULL AND value != \'\'
             ORDER BY item_id ASC
             LIMIT ' . max(0, $limit) . ' OFFSET ' . max(0, $offset)
        );

        return array_map(static fn (array $row): int => (int) $row['item_id'], $rows);
    }

    public function getByItemId(int $itemId): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT id, item_id, name, name_en, value FROM ' . self::TABLE . '
             WHERE item_id = :item_id AND value IS NOT NULL AND value != \'\'
             ORDER BY id ASC',
            ['item_id' => $itemId]
        );

        return array_map($this->hydrate(...), $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): ItemAdditionalContentInterface
    {
        return new ItemAdditionalContent(
            (int) $row['id'],
            (int) $row['item_id'],
            $this->toNullableString($row, 'name_en'),
            $this->toNullableString($row, 'name'),
            $this->toNullableString($row, 'value')
        );
    }
}
