<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Config;

use Magento\Catalog\Model\Product as MagentoProductModel;
use Magento\Eav\Model\Config as EavConfig;

class DefaultAttributeSetProvider
{
    public function __construct(
        private readonly EavConfig $eavConfig
    ) {
    }

    public function getDefaultAttributeSetId(): int
    {
        return (int) $this->eavConfig
            ->getEntityType(MagentoProductModel::ENTITY)
            ->getDefaultAttributeSetId();
    }
}
