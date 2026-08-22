<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Api\Data;

interface MagentoSimpleProductInterface
{
    public function getEccubeProductId(): int;

    public function getEccubeItemId(): ?int;

    public function getSku(): string;

    public function getName(): string;

    public function isEnabled(): bool;

    /**
     * \Magento\Catalog\Model\Product\Visibility constant. Products that
     * belong to a group default to NOT_VISIBLE_INDIVIDUALLY (they're only
     * reachable through their parent Grouped Product); standalone products
     * (no item_id) default to catalog+search.
     */
    public function getVisibility(): int;

    public function getAttributeSetId(): int;

    /**
     * Decimal as string, matching Api\Data\ProductInterface::getPrice().
     */
    public function getPrice(): ?string;

    public function getStockQuantity(): int;

    public function isInStock(): bool;

    /**
     * dtb_product.cad_unavailable_check - whether CAD data is explicitly
     * unavailable for this product. Migrated as the boolean EAV attribute
     * "cad_unavailable" on the Simple Product.
     */
    public function isCadUnavailable(): bool;

    /**
     * True when the EC-CUBE source had a genuinely NULL price and
     * ProductMapper substituted a 0.00 fallback so Magento's own
     * required-attribute check on Price would not reject the product.
     * ProductImporter uses this to mark the resulting map row
     * ProductMap::STATUS_NEEDS_REVIEW instead of the normal
     * imported/updated status, so these products surface separately for
     * manual pricing follow-up rather than blending into the normal count.
     */
    public function priceNeedsReview(): bool;

    public function getContentHash(): string;
}
