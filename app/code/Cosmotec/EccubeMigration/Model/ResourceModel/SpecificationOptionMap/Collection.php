<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\ResourceModel\SpecificationOptionMap;

use Cosmotec\EccubeMigration\Model\ResourceModel\SpecificationOptionMap as SpecificationOptionMapResource;
use Cosmotec\EccubeMigration\Model\SpecificationOptionMap as SpecificationOptionMapModel;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(SpecificationOptionMapModel::class, SpecificationOptionMapResource::class);
    }
}
