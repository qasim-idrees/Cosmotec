<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Api;

use Cosmotec\EccubeMigration\Api\Data\SpecificationInterface;
use Cosmotec\EccubeMigration\Api\Data\SpecificationOptionInterface;
use Cosmotec\EccubeMigration\Model\Connection\EccubeConnectionException;

/**
 * Read-only access to EC-CUBE's specification structure for analysis.
 * Deliberately has no write methods — the analyze:* commands must not be
 * able to mutate anything, and Magento-side attribute creation (a later
 * milestone) will use its own separate write path.
 */
interface SpecificationRepositoryInterface
{
    /**
     * All specifications with usage statistics and classification.
     *
     * @return SpecificationInterface[]
     * @throws EccubeConnectionException
     */
    public function getAllWithUsage(): array;

    /**
     * Options for one specification, ordered by the source's own sort_no
     * (never alphabetically - see SpecificationOptionInterface).
     *
     * @return SpecificationOptionInterface[]
     * @throws EccubeConnectionException
     */
    public function getOptionsBySpecificationId(int $specificationId): array;

    /**
     * Top-level EC-CUBE categories (hierarchy = 1), ordered by sort_no.
     *
     * @return array<int, array{id: int, name: string, name_en: string, sort_no: int}>
     * @throws EccubeConnectionException
     */
    public function getTopLevelCategories(): array;

    /**
     * All descendant category IDs (including the root itself) for a
     * top-level category.
     *
     * @return int[]
     * @throws EccubeConnectionException
     */
    public function getDescendantCategoryIds(int $rootCategoryId): array;

    /**
     * Specification usage aggregated for the items in the given category
     * subtree.
     *
     * @param int[] $categoryIds
     * @return array{
     *     item_count: int,
     *     product_count: int,
     *     item_scope_specification_ids: int[],
     *     product_scope_specification_ids: int[],
     *     selectable_specification_ids: int[]
     * }
     * @throws EccubeConnectionException
     */
    public function getSpecificationUsageForCategories(array $categoryIds): array;

    /**
     * Items that belong to more than one top-level category tree — these
     * need an explicit attribute-set decision rather than a silent
     * tie-break.
     *
     * @param array<int, int[]> $topLevelDescendantMap topLevelCategoryId => descendant category ids
     * @return array<int, int[]> itemId => list of top-level category ids
     * @throws EccubeConnectionException
     */
    public function getItemsInMultipleTopLevelCategories(array $topLevelDescendantMap): array;

    /**
     * dtb_category_item rows for one item, ordered per the source's own
     * ordering (sort_no DESC) with lowest category_id as an explicit
     * secondary tie-break. Feeds the PENDING (see BUILD_STATUS.md)
     * attribute-set tie-break rule for items spanning multiple top-level
     * category trees.
     *
     * @return array<int, array{category_id: int, sort_no: int}>
     * @throws EccubeConnectionException
     */
    public function getItemCategoryOrdering(int $itemId): array;
}
