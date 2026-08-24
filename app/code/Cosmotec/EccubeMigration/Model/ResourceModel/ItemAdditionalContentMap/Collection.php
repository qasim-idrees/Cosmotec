<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\ResourceModel\ItemAdditionalContentMap;

use Cosmotec\EccubeMigration\Model\ItemAdditionalContentMap as ItemAdditionalContentMapModel;
use Cosmotec\EccubeMigration\Model\ResourceModel\ItemAdditionalContentMap as ItemAdditionalContentMapResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(ItemAdditionalContentMapModel::class, ItemAdditionalContentMapResource::class);
    }
}
