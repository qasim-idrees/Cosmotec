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

/**
 * Decides how a dtb_item row becomes a Magento parent product. The
 * confirmed mapping today is Item -> Grouped Product (GroupedProductStrategy).
 * Adding Configurable or Bundle support later means adding a new class that
 * implements this interface and registering it in the strategy pool
 * (etc/di.xml) — nothing else in the pipeline (Validator, Importer) needs
 * to change.
 */
interface ProductTypeStrategyInterface
{
    /**
     * Magento product type_id this strategy produces, e.g. 'grouped'.
     */
    public function getTypeId(): string;

    /**
     * @param int[] $categoryIds Already-resolved Magento category entity_ids
     */
    public function build(ItemInterface $item, int $attributeSetId, array $categoryIds): MagentoParentProductInterface;
}
