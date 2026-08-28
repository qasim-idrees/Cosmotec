<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Api\Data;

/**
 * The Magento-target shape for a "parent" product built from a dtb_item
 * row. The concrete type_id (grouped/configurable/bundle) is decided by
 * whichever Model\Mapper\Strategy\ProductTypeStrategyInterface produced it
 * — this DTO itself is type-agnostic.
 */
interface MagentoParentProductInterface
{
    public function getEccubeItemId(): int;

    public function getSku(): string;

    public function getName(): string;

    /**
     * Magento product type_id: 'grouped' today; 'configurable' / 'bundle'
     * once additional strategies are registered.
     */
    public function getTypeId(): string;

    public function isEnabled(): bool;

    public function getVisibility(): int;

    public function getAttributeSetId(): int;

    /**
     * dtb_item.description_en (English preferred), falling back to
     * dtb_item.description (Japanese) when English is unavailable - see
     * ItemMapper/GroupedProductStrategy::resolveShortDescription(). This
     * is the same content EC-CUBE's own storefront renders as the item's
     * description (Item.descriptionWithLocale in detail.twig,
     * source-confirmed), mapped to Magento's native Grouped Product
     * short_description attribute.
     */
    public function getShortDescription(): ?string;

    /**
     * Already-resolved Magento category entity_ids.
     *
     * @return int[]
     */
    public function getCategoryIds(): array;

    public function getContentHash(): string;
}
