<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\ResourceModel\ProductReferenceMap;

use Cosmotec\EccubeMigration\Model\ProductReferenceMap as ProductReferenceMapModel;
use Cosmotec\EccubeMigration\Model\ResourceModel\ProductReferenceMap as ProductReferenceMapResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(ProductReferenceMapModel::class, ProductReferenceMapResource::class);
    }
}
