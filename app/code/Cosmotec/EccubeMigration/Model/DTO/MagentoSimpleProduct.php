<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\DTO;

use Cosmotec\EccubeMigration\Api\Data\MagentoSimpleProductInterface;

final class MagentoSimpleProduct implements MagentoSimpleProductInterface
{
    private readonly string $contentHash;

    public function __construct(
        private readonly int $eccubeProductId,
        private readonly ?int $eccubeItemId,
        private readonly string $sku,
        private readonly string $name,
        private readonly bool $enabled,
        private readonly int $visibility,
        private readonly int $attributeSetId,
        private readonly ?string $price,
        private readonly int $stockQuantity,
        private readonly bool $inStock,
        private readonly bool $cadUnavailable = false,
        private readonly bool $priceNeedsReview = false
    ) {
        $this->contentHash = hash('sha256', implode('|', [
            $this->sku,
            $this->name,
            $this->enabled ? '1' : '0',
            $this->visibility,
            $this->attributeSetId,
            $this->price ?? '',
            $this->stockQuantity,
            $this->inStock ? '1' : '0',
            $this->cadUnavailable ? '1' : '0',
        ]));
    }

    public function getEccubeProductId(): int
    {
        return $this->eccubeProductId;
    }

    public function getEccubeItemId(): ?int
    {
        return $this->eccubeItemId;
    }

    public function getSku(): string
    {
        return $this->sku;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getVisibility(): int
    {
        return $this->visibility;
    }

    public function getAttributeSetId(): int
    {
        return $this->attributeSetId;
    }

    public function getPrice(): ?string
    {
        return $this->price;
    }

    public function getStockQuantity(): int
    {
        return $this->stockQuantity;
    }

    public function isInStock(): bool
    {
        return $this->inStock;
    }

    public function isCadUnavailable(): bool
    {
        return $this->cadUnavailable;
    }

    public function priceNeedsReview(): bool
    {
        return $this->priceNeedsReview;
    }

    public function getContentHash(): string
    {
        return $this->contentHash;
    }
}
