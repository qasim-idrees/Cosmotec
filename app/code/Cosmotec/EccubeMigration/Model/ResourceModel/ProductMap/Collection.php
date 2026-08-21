<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\ResourceModel\ProductMap;

use Cosmotec\EccubeMigration\Model\ProductMap as ProductMapModel;
use Cosmotec\EccubeMigration\Model\ResourceModel\ProductMap as ProductMapResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(ProductMapModel::class, ProductMapResource::class);
    }
}
