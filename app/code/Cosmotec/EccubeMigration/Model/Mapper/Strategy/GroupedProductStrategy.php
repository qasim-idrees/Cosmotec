<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Mapper\Strategy;

use Cosmotec\EccubeMigration\Api\Data\ItemInterface;
use Cosmotec\EccubeMigration\Api\Data\MagentoParentProductInterface;
use Cosmotec\EccubeMigration\Model\DTO\MagentoParentProduct;
use Magento\Catalog\Model\Product\Visibility;

class GroupedProductStrategy implements ProductTypeStrategyInterface
{
    private const SKU_PREFIX = 'ECCUBE-ITEM-';

    public function getTypeId(): string
    {
        return 'grouped';
    }

    public function build(ItemInterface $item, int $attributeSetId, array $categoryIds): MagentoParentProductInterface
    {
        return new MagentoParentProduct(
            $item->getId(),
            self::SKU_PREFIX . $item->getId(),
            $this->resolveName($item),
            $this->getTypeId(),
            $item->getDisplayStatusId() === 1,
            Visibility::VISIBILITY_BOTH,
            $attributeSetId,
            $categoryIds
        );
    }

    private function resolveName(ItemInterface $item): string
    {
        $name = trim($item->getNameEn());

        return $name !== '' ? $name : sprintf('item-%d', $item->getId());
    }
}
