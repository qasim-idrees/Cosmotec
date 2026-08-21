<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\ResourceModel\CouplingProductMap;

use Cosmotec\EccubeMigration\Model\CouplingProductMap as CouplingProductMapModel;
use Cosmotec\EccubeMigration\Model\ResourceModel\CouplingProductMap as CouplingProductMapResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(CouplingProductMapModel::class, CouplingProductMapResource::class);
    }
}
