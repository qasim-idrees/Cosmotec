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
 * One dtb_coupling_product row - "Connection Parts": an Item (parent)
 * referencing a specific Product (child) as a compatible/connecting
 * accessory.
 *
 * SOURCE-CONFIRMED (docs/ECCUBE_SPECIFICATION_ARCHITECTURE.md §4,
 * app/Customize/Controller/ProductController.php): direction is Item ->
 * Product, cross-product-type, shown on the parent's "Connection parts"
 * page section, gated on both the target Product's and its own parent
 * Item's DisplayStatus = DISPLAY_SHOW. Structurally and semantically
 * distinct from dtb_related_product - must never share a Magento link
 * type with Related Products.
 */
interface CouplingProductInterface
{
    public function getId(): int;

    public function getItemId(): int;

    public function getProductId(): int;
}
