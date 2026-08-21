<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\DTO;

use Cosmotec\EccubeMigration\Api\Data\InventoryRecordInterface;
use Cosmotec\EccubeMigration\Api\Data\ProductClassInterface;

final class InventoryRecord implements InventoryRecordInterface
{
    /**
     * @param ProductClassInterface[] $productClasses
     */
    public function __construct(
        private readonly int $productId,
        private readonly ?string $productCode,
        private readonly ?int $stockQuantity,
        private readonly bool $stockLimitedOnly,
        private readonly array $productClasses
    ) {
    }

    public function getProductId(): int
    {
        return $this->productId;
    }

    public function getProductCode(): ?string
    {
        return $this->productCode;
    }

    public function getStockQuantity(): ?int
    {
        return $this->stockQuantity;
    }

    public function isStockLimitedOnly(): bool
    {
        return $this->stockLimitedOnly;
    }

    public function getProductClasses(): array
    {
        return $this->productClasses;
    }
}
