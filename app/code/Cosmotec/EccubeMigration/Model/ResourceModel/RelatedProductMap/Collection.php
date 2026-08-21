<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\ResourceModel\RelatedProductMap;

use Cosmotec\EccubeMigration\Model\RelatedProductMap as RelatedProductMapModel;
use Cosmotec\EccubeMigration\Model\ResourceModel\RelatedProductMap as RelatedProductMapResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(RelatedProductMapModel::class, RelatedProductMapResource::class);
    }
}
