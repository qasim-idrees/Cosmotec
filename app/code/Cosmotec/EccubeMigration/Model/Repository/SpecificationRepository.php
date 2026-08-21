<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Repository;

use Cosmotec\EccubeMigration\Api\Data\SpecificationInterface;
use Cosmotec\EccubeMigration\Api\SpecificationRepositoryInterface;
use Cosmotec\EccubeMigration\Model\DTO\Specification;
use Cosmotec\EccubeMigration\Model\DTO\SpecificationOption;

/**
 * Read-only. Every query here is a SELECT — this repository intentionally
 * exposes no way to write to either database.
 *
 * Scope note (important, DATABASE-CONFIRMED): parent-vs-child scope comes
 * from dtb_item_specification.type (1 = ITEM, 2 = PRODUCT), NOT from
 * dtb_specification.type, which is 0 for all 360 rows in production data.
 * Scope is therefore a property of the (item, specification) pair, and 80
 * specifications are legitimately used at BOTH scopes.
 */
class SpecificationRepository extends AbstractEccubeRepository implements SpecificationRepositoryInterface
{
    private const TYPE_ITEM = 1;
    private const TYPE_PRODUCT = 2;

    public function getAllWithUsage(): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT
                s.id,
                s.specification_group_id,
                s.name,
                s.name_en,
                s.sort_no,
                (SELECT COUNT(*) FROM dtb_specification_class sc WHERE sc.specification_id = s.id) AS option_count,
                (SELECT COUNT(*) FROM dtb_item_specification isp
                    WHERE isp.specification_id = s.id AND isp.type = ' . self::TYPE_ITEM . ') AS item_scope_count,
                (SELECT COUNT(*) FROM dtb_item_specification isp
                    WHERE isp.specification_id = s.id AND isp.type = ' . self::TYPE_PRODUCT . ') AS product_scope_count,
                (SELECT COUNT(*) FROM dtb_item_specification isp
                    WHERE isp.specification_id = s.id AND isp.selectable = 1) AS selectable_count
             FROM dtb_specification s
             ORDER BY s.sort_no ASC, s.id ASC'
        );

        return array_map(
            static fn (array $row): SpecificationInterface => new Specification(
                (int) $row['id'],
                $row['specification_group_id'] === null ? null : (int) $row['specification_group_id'],
                (string) $row['name'],
                (string) $row['name_en'],
                (int) $row['sort_no'],
                (int) $row['option_count'],
                ((int) $row['item_scope_count']) > 0,
                ((int) $row['product_scope_count']) > 0,
                (int) $row['selectable_count']
            ),
            $rows
        );
    }

    public function getOptionsBySpecificationId(int $specificationId): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT id, specification_id, name, name_en, sort_no
             FROM dtb_specification_class
             WHERE specification_id = :specification_id
             ORDER BY sort_no ASC, id ASC',
            ['specification_id' => $specificationId]
        );

        return array_map(
            static fn (array $row): SpecificationOption => new SpecificationOption(
                (int) $row['id'],
                (int) $row['specification_id'],
                (string) $row['name'],
                (string) $row['name_en'],
                (int) $row['sort_no']
            ),
            $rows
        );
    }

    public function getTopLevelCategories(): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT id, category_name, category_name_en, sort_no
             FROM dtb_category
             WHERE hierarchy = 1
             ORDER BY sort_no ASC, id ASC'
        );

        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'name' => (string) $row['category_name'],
                'name_en' => (string) $row['category_name_en'],
                'sort_no' => (int) $row['sort_no'],
            ],
            $rows
        );
    }

    public function getDescendantCategoryIds(int $rootCategoryId): array
    {
        // Iterative descent rather than a recursive CTE: EC-CUBE 4.0.0
        // supports MySQL 5.7, which has no CTE support. Category depth
        // here is small (max hierarchy observed is shallow), so the extra
        // round trips are negligible.
        $all = [$rootCategoryId];
        $frontier = [$rootCategoryId];

        while ($frontier !== []) {
            $placeholders = implode(',', array_map('intval', $frontier));
            $rows = $this->connection->fetchAll(
                'SELECT id FROM dtb_category WHERE parent_category_id IN (' . $placeholders . ')'
            );

            $frontier = [];

            foreach ($rows as $row) {
                $id = (int) $row['id'];

                if (!in_array($id, $all, true)) {
                    $all[] = $id;
                    $frontier[] = $id;
                }
            }
        }

        return $all;
    }

    public function getSpecificationUsageForCategories(array $categoryIds): array
    {
        if ($categoryIds === []) {
            return [
                'item_count' => 0,
                'product_count' => 0,
                'item_scope_specification_ids' => [],
                'product_scope_specification_ids' => [],
                'selectable_specification_ids' => [],
            ];
        }

        $in = implode(',', array_map('intval', $categoryIds));

        $itemCount = (int) $this->connection->fetchScalar(
            'SELECT COUNT(DISTINCT ci.item_id) FROM dtb_category_item ci WHERE ci.category_id IN (' . $in . ')'
        );

        $productCount = (int) $this->connection->fetchScalar(
            'SELECT COUNT(DISTINCT p.id)
             FROM dtb_product p
             INNER JOIN dtb_category_item ci ON ci.item_id = p.item_id
             WHERE ci.category_id IN (' . $in . ')'
        );

        return [
            'item_count' => $itemCount,
            'product_count' => $productCount,
            'item_scope_specification_ids' => $this->fetchSpecIds($in, 'isp.type = ' . self::TYPE_ITEM),
            'product_scope_specification_ids' => $this->fetchSpecIds($in, 'isp.type = ' . self::TYPE_PRODUCT),
            'selectable_specification_ids' => $this->fetchSpecIds($in, 'isp.selectable = 1'),
        ];
    }

    /**
     * @return int[]
     */
    private function fetchSpecIds(string $categoryIdList, string $condition): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT DISTINCT isp.specification_id
             FROM dtb_item_specification isp
             INNER JOIN dtb_category_item ci ON ci.item_id = isp.item_id
             WHERE ci.category_id IN (' . $categoryIdList . ')
               AND ' . $condition . '
               AND isp.specification_id IS NOT NULL'
        );

        return array_map(static fn (array $row): int => (int) $row['specification_id'], $rows);
    }

    /**
     * dtb_category_item rows for one item, ordered exactly per the source's
     * own ordering (Item::$CategoryItems is @ORM\OrderBy sort_no DESC),
     * with lowest category_id as an explicit secondary tie-break for rows
     * that share a sort_no. Used by AttributeSetResolver to implement the
     * PENDING (see BUILD_STATUS.md) tie-break rule for the 89 items
     * spanning multiple top-level category trees.
     *
     * @return array<int, array{category_id: int, sort_no: int}>
     */
    public function getItemCategoryOrdering(int $itemId): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT category_id, sort_no
             FROM dtb_category_item
             WHERE item_id = :item_id
             ORDER BY sort_no DESC, category_id ASC',
            ['item_id' => $itemId]
        );

        return array_map(
            static fn (array $row): array => [
                'category_id' => (int) $row['category_id'],
                'sort_no' => (int) $row['sort_no'],
            ],
            $rows
        );
    }

    public function getItemsInMultipleTopLevelCategories(array $topLevelDescendantMap): array
    {
        $itemTopLevels = [];

        foreach ($topLevelDescendantMap as $topLevelId => $categoryIds) {
            if ($categoryIds === []) {
                continue;
            }

            $in = implode(',', array_map('intval', $categoryIds));
            $rows = $this->connection->fetchAll(
                'SELECT DISTINCT ci.item_id FROM dtb_category_item ci WHERE ci.category_id IN (' . $in . ')'
            );

            foreach ($rows as $row) {
                $itemId = (int) $row['item_id'];
                $itemTopLevels[$itemId][] = (int) $topLevelId;
            }
        }

        return array_filter($itemTopLevels, static fn (array $tops): bool => count($tops) > 1);
    }
}
