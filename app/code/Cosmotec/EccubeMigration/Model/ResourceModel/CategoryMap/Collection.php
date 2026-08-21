<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\ResourceModel\CategoryMap;

use Cosmotec\EccubeMigration\Model\CategoryMap as CategoryMapModel;
use Cosmotec\EccubeMigration\Model\ResourceModel\CategoryMap as CategoryMapResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(CategoryMapModel::class, CategoryMapResource::class);
    }
}
