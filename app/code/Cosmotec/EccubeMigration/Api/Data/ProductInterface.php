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
 * Maps 1:1 to dtb_product. Confirmed to map to Magento Simple Product, with
 * dtb_product.stock_quantity -> Magento Inventory Qty.
 *
 * This is a customized EC-CUBE install: unlike vanilla EC-CUBE 4.0.0,
 * dtb_product itself carries price, stock_quantity and price-expiration
 * fields directly (vanilla EC-CUBE keeps those on dtb_product_class /
 * dtb_product_stock). This is confirmed both by the dumped schema and by
 * app/Customize/Repository/ProductRepositoryExtend.php, which filters
 * in-stock/out-of-stock directly on p.stock_quantity (NULL or 0 = out of
 * stock). dtb_product_class / dtb_product_stock still exist in parallel
 * (see ProductClassInterface) and will be reconciled explicitly in
 * Milestone 7 (Inventory) rather than assumed away.
 */
interface ProductInterface
{
    public function getId(): int;

    public function getItemId(): ?int;

    public function getName(): string;

    public function getNameEn(): string;

    public function getShortName(): string;

    public function getShortNameEn(): string;

    public function getProductCode(): ?string;

    public function getModel(): string;

    public function getMakerPartNumber(): ?string;

    public function getNote(): ?string;

    public function getDescriptionList(): ?string;

    public function getDescriptionDetail(): ?string;

    public function getSearchWord(): ?string;

    public function getFreeArea(): ?string;

    /**
     * Decimal returned as string to preserve precision; cast at the Mapper
     * boundary, never before.
     */
    public function getPrice(): ?string;

    public function getStockQuantity(): ?int;

    public function isStockLimitedOnly(): bool;

    public function isCadUnavailable(): bool;

    public function isPriceExpirationCheck(): bool;

    public function getPriceExpirationDate(): ?\DateTimeImmutable;

    public function getPriceExpirationJudgment(): int;

    public function getMinimumSalesQuantity(): ?int;

    public function getSortNo(): int;

    /**
     * References mtb_product_status: 1 = SHOW, 2 = HIDE, 3 = ABOLISHED.
     */
    public function getProductStatusId(): ?int;

    /**
     * References mtb_display_status: 1 = DISPLAY_SHOW, 2 = DISPLAY_HIDE.
     */
    public function getDisplayStatusId(): ?int;

    public function getSaleTypeId(): ?int;

    public function getCreateDate(): \DateTimeImmutable;

    public function getUpdateDate(): \DateTimeImmutable;
}
