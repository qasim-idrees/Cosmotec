<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class CouplingProductMap extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('eccube_coupling_product_map', 'entity_id');
    }
}
