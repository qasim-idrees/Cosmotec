<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\ResourceModel\SpecificationMap;

use Cosmotec\EccubeMigration\Model\ResourceModel\SpecificationMap as SpecificationMapResource;
use Cosmotec\EccubeMigration\Model\SpecificationMap as SpecificationMapModel;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(SpecificationMapModel::class, SpecificationMapResource::class);
    }
}
