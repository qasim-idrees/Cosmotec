<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\ResourceModel\SyncHistory;

use Cosmotec\EccubeMigration\Model\ResourceModel\SyncHistory as SyncHistoryResource;
use Cosmotec\EccubeMigration\Model\SyncHistory as SyncHistoryModel;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(SyncHistoryModel::class, SyncHistoryResource::class);
    }
}
