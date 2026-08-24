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
        private readonly bool $priceNeedsReview = false,
        private readonly ?string $model = null,
        private readonly ?string $makerPartNumber = null,
        private readonly ?int $minimumSalesQuantity = null
    ) {
        // attributeSetId is deliberately excluded: ProductMapper always
        // computes Magento's Default set here (DefaultAttributeSetProvider)
        // - the real EC-CUBE-category-derived attribute set is assigned
        // separately and later by assign:product-attribute-sets, and
        // ProductImporter::persist() correctly never re-applies this field
        // on update. Including it in the hash would mean the computed
        // value permanently differs from the product's real, correctly-
        // assigned attribute set forever, so the hash would never
        // stabilize and every already-imported product would show as
        // "needing an update" on every single future run - defeating the
        // whole point of the hash-gate skip this field is otherwise
        // unrelated to.
        $this->contentHash = hash('sha256', implode('|', [
            $this->sku,
            $this->name,
            $this->enabled ? '1' : '0',
            $this->visibility,
            $this->price ?? '',
            $this->stockQuantity,
            $this->inStock ? '1' : '0',
            $this->cadUnavailable ? '1' : '0',
            $this->model ?? '',
            $this->makerPartNumber ?? '',
            $this->minimumSalesQuantity ?? '',
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

    public function getModel(): ?string
    {
        return $this->model;
    }

    public function getMakerPartNumber(): ?string
    {
        return $this->makerPartNumber;
    }

    public function getMinimumSalesQuantity(): ?int
    {
        return $this->minimumSalesQuantity;
    }

    public function getContentHash(): string
    {
        return $this->contentHash;
    }
}
