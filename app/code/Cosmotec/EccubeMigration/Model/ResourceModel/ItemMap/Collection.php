<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\ResourceModel\ItemMap;

use Cosmotec\EccubeMigration\Model\ItemMap as ItemMapModel;
use Cosmotec\EccubeMigration\Model\ResourceModel\ItemMap as ItemMapResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(ItemMapModel::class, ItemMapResource::class);
    }
}
