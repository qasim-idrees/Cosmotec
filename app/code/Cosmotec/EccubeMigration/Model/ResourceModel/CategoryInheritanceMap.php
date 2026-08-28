<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * Junction-table resource for eccube_category_inheritance_map - see
 * Model/Category/ChildCategoryInheritanceService for the read/write flow.
 * No AbstractModel/Collection layer, same style as
 * ProductSpecificationValueMap::deleteOrphaned() - this table has no
 * per-row business state beyond the (product, category, source item) tuple
 * itself.
 */
class CategoryInheritanceMap extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('eccube_category_inheritance_map', 'entity_id');
    }

    /**
     * @return int[]
     */
    public function getCategoryIdsForProduct(int $magentoProductId): array
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getMainTable(), ['magento_category_id'])
            ->where('magento_product_id = ?', $magentoProductId);

        return array_map('intval', $connection->fetchCol($select));
    }

    /**
     * Makes the tracked inherited-category set for this product exactly
     * $categoryIds - deletes rows no longer present, inserts new ones,
     * leaves unchanged rows untouched. A no-op write when nothing changed,
     * which is what makes repeated calls with the same input idempotent.
     *
     * @param int[] $categoryIds
     */
    public function replaceForProduct(int $magentoProductId, int $eccubeItemId, array $categoryIds): void
    {
        $connection = $this->getConnection();
        $categoryIds = array_values(array_unique(array_map('intval', $categoryIds)));
        $existing = $this->getCategoryIdsForProduct($magentoProductId);

        $toDelete = array_values(array_diff($existing, $categoryIds));
        $toInsert = array_values(array_diff($categoryIds, $existing));

        if ($toDelete !== []) {
            $connection->delete(
                $this->getMainTable(),
                [
                    'magento_product_id = ?' => $magentoProductId,
                    'magento_category_id IN (?)' => $toDelete,
                ]
            );
        }

        if ($toInsert !== []) {
            $rows = array_map(
                static fn (int $categoryId): array => [
                    'magento_product_id' => $magentoProductId,
                    'magento_category_id' => $categoryId,
                    'eccube_item_id' => $eccubeItemId,
                ],
                $toInsert
            );

            $connection->insertMultiple($this->getMainTable(), $rows);
        }
    }
}
