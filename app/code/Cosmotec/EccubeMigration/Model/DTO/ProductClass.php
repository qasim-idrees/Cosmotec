<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\DTO;

use Cosmotec\EccubeMigration\Api\Data\ProductClassInterface;

final class ProductClass implements ProductClassInterface
{
    public function __construct(
        private readonly int $id,
        private readonly ?int $productId,
        private readonly ?string $productCode,
        private readonly ?string $stock,
        private readonly bool $stockUnlimited,
        private readonly ?string $price01,
        private readonly string $price02,
        private readonly ?string $deliveryFee,
        private readonly bool $visible,
        private readonly ?string $currencyCode,
        private readonly ?int $saleTypeId,
        private readonly ?int $classCategoryId1,
        private readonly ?int $classCategoryId2
    ) {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getProductId(): ?int
    {
        return $this->productId;
    }

    public function getProductCode(): ?string
    {
        return $this->productCode;
    }

    public function getStock(): ?string
    {
        return $this->stock;
    }

    public function isStockUnlimited(): bool
    {
        return $this->stockUnlimited;
    }

    public function getPrice01(): ?string
    {
        return $this->price01;
    }

    public function getPrice02(): string
    {
        return $this->price02;
    }

    public function getDeliveryFee(): ?string
    {
        return $this->deliveryFee;
    }

    public function isVisible(): bool
    {
        return $this->visible;
    }

    public function getCurrencyCode(): ?string
    {
        return $this->currencyCode;
    }

    public function getSaleTypeId(): ?int
    {
        return $this->saleTypeId;
    }

    public function getClassCategoryId1(): ?int
    {
        return $this->classCategoryId1;
    }

    public function getClassCategoryId2(): ?int
    {
        return $this->classCategoryId2;
    }
}
