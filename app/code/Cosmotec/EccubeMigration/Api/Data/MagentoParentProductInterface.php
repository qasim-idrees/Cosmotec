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
     * Already-resolved Magento category entity_ids.
     *
     * @return int[]
     */
    public function getCategoryIds(): array;

    public function getContentHash(): string;
}
