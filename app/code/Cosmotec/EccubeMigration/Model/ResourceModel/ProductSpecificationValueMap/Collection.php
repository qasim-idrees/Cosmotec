<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\ResourceModel\ProductSpecificationValueMap;

use Cosmotec\EccubeMigration\Model\ProductSpecificationValueMap as ProductSpecificationValueMapModel;
use Cosmotec\EccubeMigration\Model\ResourceModel\ProductSpecificationValueMap as ProductSpecificationValueMapResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(ProductSpecificationValueMapModel::class, ProductSpecificationValueMapResource::class);
    }
}
