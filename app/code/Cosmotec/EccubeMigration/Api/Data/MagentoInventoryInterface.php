<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Api\Data;

interface MagentoInventoryInterface
{
    public function getEccubeProductId(): int;

    public function getSku(): string;

    public function getQty(): float;

    public function isInStock(): bool;

    /**
     * false = unlimited stock (dtb_product.stock_limited_only = 0): the
     * product is always salable regardless of qty. true = tracked stock.
     */
    public function isStockManaged(): bool;

    public function getContentHash(): string;
}
