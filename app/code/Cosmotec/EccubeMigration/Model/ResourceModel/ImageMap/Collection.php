<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\ResourceModel\ImageMap;

use Cosmotec\EccubeMigration\Model\ImageMap as ImageMapModel;
use Cosmotec\EccubeMigration\Model\ResourceModel\ImageMap as ImageMapResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(ImageMapModel::class, ImageMapResource::class);
    }
}
