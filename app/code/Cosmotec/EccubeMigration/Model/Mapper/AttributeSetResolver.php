<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Mapper;

use Cosmotec\EccubeMigration\Api\SpecificationRepositoryInterface;

/**
 * PENDING BUSINESS DECISION - see BUILD_STATUS.md.
 *
 * Resolves which ONE of the 8 top-level-category attribute sets a given
 * EC-CUBE item should use. 8 top-level categories cover most items
 * unambiguously; 89 items (DATABASE-CONFIRMED, live-verified this
 * session) belong to more than one top-level tree and need a tie-break.
 *
 * Current standing design (per the continuation prompt's explicit
 * instruction: "keep the proposed deterministic rule sort_no DESC, then
 * lowest category_id as tie-breaker, but mark it as pending confirmation"):
 * take the item's dtb_category_item rows in sort_no DESC order (lowest
 * category_id breaking ties on equal sort_no), and use the top-level tree
 * of the first row that resolves to one of the 8 known trees. This is a
 * SOURCE-DERIVED ordering (Item::$CategoryItems really is sort_no DESC in
 * EC-CUBE), not a SOURCE-DEFINED "primary category" rule - EC-CUBE itself
 * never picks one category, it renders every breadcrumb path. Because
 * attribute sets are permissive unions (docs/SPECIFICATION_MAGENTO_DATA_MODEL.md
 * ADDENDUM D), a "wrong" choice between two overlapping sets is low-impact:
 * the item still gets a set containing every attribute it needs.
 *
 * Not yet wired into ItemMapper/ProductMapper - those classes are proven,
 * already-verified pipeline and are intentionally left untouched during
 * this read-only-preparation phase. Wiring them in is a small, explicit
 * follow-up once attribute sets actually exist in Magento.
 */
class AttributeSetResolver
{
    /**
     * Synthetic top-level "category" id for the confirmed-uncategorized
     * items (Round 32/45: zero dtb_category_item rows, so no real EC-CUBE
     * category id could ever collide with this). Not a real
     * dtb_category.id - callers that need the "Uncategorized" fallback set
     * substitute this in place of a null resolveTopLevelCategoryId()
     * result; resolveTopLevelCategoryId() itself never returns it, so its
     * contract (null = no real top-level tree found) stays honest.
     *
     * Deliberately a large positive value, not -1: eccube_attribute_set_map.
     * eccube_top_level_category_id is unsigned int (avoiding a schema
     * change for a purely synthetic bookkeeping id), and real EC-CUBE
     * category ids observed in production are small (single/triple digits).
     */
    public const UNCATEGORIZED_TOP_LEVEL_ID = 999999999;

    /** @var array<int, int[]>|null topLevelCategoryId => descendant category ids, built once and cached */
    private ?array $descendantMap = null;

    /** @var array<int, int>|null categoryId => topLevelCategoryId, the reverse lookup */
    private ?array $categoryToTopLevel = null;

    public function __construct(
        private readonly SpecificationRepositoryInterface $specificationRepository
    ) {
    }

    /**
     * @return int|null the winning top-level dtb_category.id, or null if
     *                   the item belongs to no known top-level tree at all
     */
    public function resolveTopLevelCategoryId(int $itemId): ?int
    {
        $this->buildMapsIfNeeded();

        $ordering = $this->specificationRepository->getItemCategoryOrdering($itemId);

        foreach ($ordering as $row) {
            $topLevelId = $this->categoryToTopLevel[$row['category_id']] ?? null;

            if ($topLevelId !== null) {
                return $topLevelId;
            }
        }

        return null;
    }

    private function buildMapsIfNeeded(): void
    {
        if ($this->descendantMap !== null) {
            return;
        }

        $this->descendantMap = [];
        $this->categoryToTopLevel = [];

        foreach ($this->specificationRepository->getTopLevelCategories() as $topLevel) {
            $descendants = $this->specificationRepository->getDescendantCategoryIds($topLevel['id']);
            $this->descendantMap[$topLevel['id']] = $descendants;

            foreach ($descendants as $categoryId) {
                // First top-level tree to claim a shared descendant wins -
                // deterministic because getTopLevelCategories() is
                // sort_no-ordered, consistent across runs.
                if (!array_key_exists($categoryId, $this->categoryToTopLevel)) {
                    $this->categoryToTopLevel[$categoryId] = $topLevel['id'];
                }
            }
        }
    }
}
