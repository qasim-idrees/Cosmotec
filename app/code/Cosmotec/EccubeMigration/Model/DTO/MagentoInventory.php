<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\DTO;

use Cosmotec\EccubeMigration\Api\Data\MagentoInventoryInterface;

final class MagentoInventory implements MagentoInventoryInterface
{
    private readonly string $contentHash;

    public function __construct(
        private readonly int $eccubeProductId,
        private readonly string $sku,
        private readonly float $qty,
        private readonly bool $inStock,
        private readonly bool $stockManaged
    ) {
        $this->contentHash = hash('sha256', implode('|', [
            $this->sku,
            $this->qty,
            $this->inStock ? '1' : '0',
            $this->stockManaged ? '1' : '0',
        ]));
    }

    public function getEccubeProductId(): int
    {
        return $this->eccubeProductId;
    }

    public function getSku(): string
    {
        return $this->sku;
    }

    public function getQty(): float
    {
        return $this->qty;
    }

    public function isInStock(): bool
    {
        return $this->inStock;
    }

    public function isStockManaged(): bool
    {
        return $this->stockManaged;
    }

    public function getContentHash(): string
    {
        return $this->contentHash;
    }
}
