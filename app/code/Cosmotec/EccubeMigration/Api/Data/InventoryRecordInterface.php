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
 * One product's inventory picture: dtb_product's own stock_quantity (the
 * confirmed primary source per ProductRepositoryExtend.php) alongside any
 * dtb_product_class rows that exist for it. The Milestone 7 InventorySync
 * decides precedence when both are present; this DTO deliberately does not
 * pick a winner, it just carries both.
 */
interface InventoryRecordInterface
{
    public function getProductId(): int;

    public function getProductCode(): ?string;

    public function getStockQuantity(): ?int;

    public function isStockLimitedOnly(): bool;

    /**
     * @return ProductClassInterface[]
     */
    public function getProductClasses(): array;
}
