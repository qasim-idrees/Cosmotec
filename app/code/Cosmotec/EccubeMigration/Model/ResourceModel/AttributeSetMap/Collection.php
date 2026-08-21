<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\ResourceModel\AttributeSetMap;

use Cosmotec\EccubeMigration\Model\AttributeSetMap as AttributeSetMapModel;
use Cosmotec\EccubeMigration\Model\ResourceModel\AttributeSetMap as AttributeSetMapResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(AttributeSetMapModel::class, AttributeSetMapResource::class);
    }
}
