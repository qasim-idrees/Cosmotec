<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\ResourceModel\MediaMap;

use Cosmotec\EccubeMigration\Model\MediaMap as MediaMapModel;
use Cosmotec\EccubeMigration\Model\ResourceModel\MediaMap as MediaMapResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(MediaMapModel::class, MediaMapResource::class);
    }
}
