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
 * Maps 1:1 to dtb_product_class. Not part of the confirmed core mapping,
 * but read and exposed because it exists in parallel with dtb_product's own
 * price/stock columns in this customized install (see ProductInterface
 * docblock). Milestone 7 will decide precedence rather than this layer
 * silently picking one source.
 */
interface ProductClassInterface
{
    public function getId(): int;

    public function getProductId(): ?int;

    public function getProductCode(): ?string;

    /**
     * Decimal returned as string to preserve precision.
     */
    public function getStock(): ?string;

    public function isStockUnlimited(): bool;

    public function getPrice01(): ?string;

    public function getPrice02(): string;

    public function getDeliveryFee(): ?string;

    public function isVisible(): bool;

    public function getCurrencyCode(): ?string;

    public function getSaleTypeId(): ?int;

    public function getClassCategoryId1(): ?int;

    public function getClassCategoryId2(): ?int;
}
