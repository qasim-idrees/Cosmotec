<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Category;

use Cosmotec\EccubeMigration\Api\CategoryInheritanceMapRepositoryInterface;
use Cosmotec\EccubeMigration\Logger\ImportLogger;
use Magento\Catalog\Api\CategoryLinkManagementInterface;
use Magento\Catalog\Api\ProductRepositoryInterface as MagentoProductRepositoryInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\GroupedProduct\Model\Product\Type\Grouped as GroupedProductType;

/**
 * Cascades a Grouped Product's Magento category assignment to its
 * associated Simple Products (EC-CUBE only assigns categories at the
 * Item/Grouped level - dtb_category_item - never at the dtb_product level,
 * confirmed via a live query: dtb_product_category exists in the source
 * schema but holds zero rows in this dataset).
 *
 * Uses a union of "categories independently on the child" + "the parent's
 * current category set", never a blind replace, so a category assigned to
 * a Simple Product some other way is never silently dropped. "Independently
 * assigned" is determined by eccube_category_inheritance_map, which
 * remembers exactly which category ids on a given child were themselves
 * put there by a previous run of this service - anything else found on the
 * product is left alone. This is what allows a later sync to correctly
 * DROP a category from a child when the parent stops having it (the
 * category was only ever there because of inheritance) while never
 * touching a category that has no inheritance record at all.
 *
 * Called from two places, matching this module's existing
 * import/sync split:
 *  - ProductRelationImporter, right after a Grouped Product's children are
 *    (re)linked, so a fresh install's documented pipeline
 *    (import:categories -> import:group-products -> import:simple-products
 *    -> import:product-relations) ends with children already categorized -
 *    at import:group-products time the children aren't linked yet, so
 *    cascading there alone would be a no-op on a first-ever import.
 *  - ItemImporter::persist() (inherited by ItemSync unchanged, per this
 *    module's "sync extends import, only the scan source differs"
 *    convention), right after the Grouped Product's own categories are
 *    (re)assigned, so an EC-CUBE-side category change picked up by
 *    sync:group-products propagates to already-linked children too.
 *
 * Reads a child's CURRENT category assignment straight from
 * catalog_category_product via ResourceConnection rather than
 * $product->getCategoryIds() - live-confirmed this round:
 * ProductRepositoryInterface::getById() caches the loaded Product instance
 * per (id, storeId) for the lifetime of the process, and
 * CategoryLinkManagementInterface::assignProductToCategories() (a
 * different write path, going through CategoryLinkRepository, not
 * ProductRepository::save()) never invalidates that cache - a second read
 * of the same child within one process would otherwise silently see
 * pre-write category data and mis-compute the union.
 */
class ChildCategoryInheritanceService
{
    private const CATEGORY_PRODUCT_TABLE = 'catalog_category_product';

    public function __construct(
        private readonly GroupedProductType $groupedProductType,
        private readonly MagentoProductRepositoryInterface $magentoProductRepository,
        private readonly CategoryLinkManagementInterface $categoryLinkManagement,
        private readonly CategoryInheritanceMapRepositoryInterface $inheritanceMapRepository,
        private readonly ResourceConnection $resourceConnection,
        private readonly ImportLogger $logger
    ) {
    }

    /**
     * @param int[] $parentCategoryIds The parent Grouped Product's current full Magento category id set
     * @return int Number of child products whose category assignment actually changed (or would, in dry-run)
     */
    public function cascade(int $eccubeItemId, int $parentMagentoProductId, array $parentCategoryIds, bool $dryRun): int
    {
        $childIds = $this->getChildProductIds($parentMagentoProductId);

        if ($childIds === []) {
            return 0;
        }

        $parentCategoryIds = array_values(array_unique(array_map('intval', $parentCategoryIds)));
        $affected = 0;

        foreach ($childIds as $childId) {
            if ($this->cascadeToChild($childId, $eccubeItemId, $parentCategoryIds, $dryRun)) {
                $affected++;
            }
        }

        return $affected;
    }

    private function cascadeToChild(int $childId, int $eccubeItemId, array $parentCategoryIds, bool $dryRun): bool
    {
        try {
            // Explicit store_id=0 (global scope) - same convention as
            // ItemImporter/ProductImporter's own product loads.
            $childProduct = $this->magentoProductRepository->getById($childId, false, 0);
        } catch (NoSuchEntityException $e) {
            $this->logger->error(sprintf(
                'Category inheritance: child product id=%d (parent EC-CUBE item id=%d) no longer exists: %s',
                $childId,
                $eccubeItemId,
                $e->getMessage()
            ));

            return false;
        }

        $currentCategoryIds = $this->getCurrentCategoryIds($childId);
        $previouslyInherited = $this->inheritanceMapRepository->getCategoryIdsForProduct($childId);
        $independentCategoryIds = array_values(array_diff($currentCategoryIds, $previouslyInherited));
        $newCategoryIds = array_values(array_unique(array_merge($independentCategoryIds, $parentCategoryIds)));

        $sortedCurrent = $currentCategoryIds;
        sort($sortedCurrent);
        $sortedNew = $newCategoryIds;
        sort($sortedNew);

        $needsAssign = $sortedNew !== $sortedCurrent;

        if ($needsAssign) {
            $this->logger->info(sprintf(
                'Category inheritance%s: child product id=%d sku=%s categories [%s] -> [%s] (parent EC-CUBE item id=%d)',
                $dryRun ? ' [DRY RUN]' : '',
                $childId,
                $childProduct->getSku(),
                implode(',', $sortedCurrent),
                implode(',', $sortedNew),
                $eccubeItemId
            ));

            if (!$dryRun) {
                try {
                    $this->categoryLinkManagement->assignProductToCategories($childProduct->getSku(), $newCategoryIds);
                } catch (LocalizedException | \Throwable $e) {
                    $this->logger->error(sprintf(
                        'Category inheritance: failed to assign categories to child product id=%d sku=%s: %s',
                        $childId,
                        $childProduct->getSku(),
                        $e->getMessage()
                    ));

                    return false;
                }
            }
        }

        if (!$dryRun) {
            $this->inheritanceMapRepository->replaceForProduct($childId, $eccubeItemId, $parentCategoryIds);
        }

        return $needsAssign;
    }

    /**
     * @return int[]
     */
    private function getCurrentCategoryIds(int $productId): array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->resourceConnection->getTableName(self::CATEGORY_PRODUCT_TABLE), ['category_id'])
            ->where('product_id = ?', $productId);

        $ids = array_map('intval', $connection->fetchCol($select));
        sort($ids);

        return $ids;
    }

    /**
     * @return int[]
     */
    private function getChildProductIds(int $parentMagentoProductId): array
    {
        $ids = [];

        foreach ($this->groupedProductType->getChildrenIds($parentMagentoProductId) as $childIdGroup) {
            foreach ((array) $childIdGroup as $childId) {
                $ids[] = (int) $childId;
            }
        }

        return array_values(array_unique($ids));
    }
}
