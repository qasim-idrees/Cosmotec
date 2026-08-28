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
            $categoryIds,
            $this->resolveShortDescription($item)
        );
    }

    /**
     * English preferred (dtb_item.description_en), Japanese fallback
     * (dtb_item.description) when English is unavailable - same policy as
     * resolveName() above and CategoryMapper::resolveDescription(). This
     * is real, populated content (89-97% of items, live-confirmed) and is
     * exactly what EC-CUBE's own storefront renders as the item
     * description (Item.descriptionWithLocale in detail.twig).
     */
    private function resolveShortDescription(ItemInterface $item): ?string
    {
        $descriptionEn = $item->getDescriptionEn();

        if ($descriptionEn !== null && trim($descriptionEn) !== '') {
            return $descriptionEn;
        }

        $description = $item->getDescription();

        return $description !== null && trim($description) !== '' ? $description : null;
    }

    /**
     * See ProductMapper::resolveName() - same language-policy fallback
     * (CLAUDE.md "Language": English preferred, Japanese fallback when
     * English is unavailable), applied here for consistency even though
     * every current dtb_item row has a populated name_en (100% - live
     * confirmed) - a fresh EC-CUBE dataset is not guaranteed to.
     */
    private function resolveName(ItemInterface $item): string
    {
        $nameEn = trim($item->getNameEn());

        if ($nameEn !== '') {
            return $nameEn;
        }

        $name = trim($item->getName());

        return $name !== '' ? $name : sprintf('item-%d', $item->getId());
    }
}
