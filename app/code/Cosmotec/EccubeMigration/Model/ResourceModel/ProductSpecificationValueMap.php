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

class ProductSpecificationValueMap extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('eccube_product_specification_value', 'entity_id');
    }

    /**
     * @param int[] $currentSourceRowIds
     */
    public function deleteOrphaned(int $eccubeProductId, int $eccubeSpecificationId, array $currentSourceRowIds): int
    {
        $connection = $this->getConnection();

        $conditions = [
            $connection->quoteInto('eccube_product_id = ?', $eccubeProductId),
            $connection->quoteInto('eccube_specification_id = ?', $eccubeSpecificationId),
        ];

        if ($currentSourceRowIds !== []) {
            $conditions[] = $connection->quoteInto('eccube_product_specification_class_id NOT IN (?)', $currentSourceRowIds);
        }

        return $connection->delete($this->getMainTable(), implode(' AND ', $conditions));
    }
}
