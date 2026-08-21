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
 * One dtb_related_product row - Product -> Product (child-level), shown
 * on the child-specific detail page. SOURCE-CONFIRMED
 * (docs/SPECIFICATION_MAGENTO_DATA_MODEL.md §8): structurally distinct
 * from Connection Parts (dtb_coupling_product, Item -> Product,
 * parent-level) - must map to a separate Magento representation.
 */
interface RelatedProductInterface
{
    public function getId(): int;

    public function getProductId(): int;

    public function getRelatedProductId(): int;
}
