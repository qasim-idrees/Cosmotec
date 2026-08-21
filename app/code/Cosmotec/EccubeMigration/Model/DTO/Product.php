<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\DTO;

use Cosmotec\EccubeMigration\Api\Data\ProductInterface;

final class Product implements ProductInterface
{
    public function __construct(
        private readonly int $id,
        private readonly ?int $itemId,
        private readonly string $name,
        private readonly string $nameEn,
        private readonly string $shortName,
        private readonly string $shortNameEn,
        private readonly ?string $productCode,
        private readonly string $model,
        private readonly ?string $makerPartNumber,
        private readonly ?string $note,
        private readonly ?string $descriptionList,
        private readonly ?string $descriptionDetail,
        private readonly ?string $searchWord,
        private readonly ?string $freeArea,
        private readonly ?string $price,
        private readonly ?int $stockQuantity,
        private readonly bool $stockLimitedOnly,
        private readonly bool $cadUnavailable,
        private readonly bool $priceExpirationCheck,
        private readonly ?\DateTimeImmutable $priceExpirationDate,
        private readonly int $priceExpirationJudgment,
        private readonly ?int $minimumSalesQuantity,
        private readonly int $sortNo,
        private readonly ?int $productStatusId,
        private readonly ?int $displayStatusId,
        private readonly ?int $saleTypeId,
        private readonly \DateTimeImmutable $createDate,
        private readonly \DateTimeImmutable $updateDate
    ) {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getItemId(): ?int
    {
        return $this->itemId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getNameEn(): string
    {
        return $this->nameEn;
    }

    public function getShortName(): string
    {
        return $this->shortName;
    }

    public function getShortNameEn(): string
    {
        return $this->shortNameEn;
    }

    public function getProductCode(): ?string
    {
        return $this->productCode;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getMakerPartNumber(): ?string
    {
        return $this->makerPartNumber;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function getDescriptionList(): ?string
    {
        return $this->descriptionList;
    }

    public function getDescriptionDetail(): ?string
    {
        return $this->descriptionDetail;
    }

    public function getSearchWord(): ?string
    {
        return $this->searchWord;
    }

    public function getFreeArea(): ?string
    {
        return $this->freeArea;
    }

    public function getPrice(): ?string
    {
        return $this->price;
    }

    public function getStockQuantity(): ?int
    {
        return $this->stockQuantity;
    }

    public function isStockLimitedOnly(): bool
    {
        return $this->stockLimitedOnly;
    }

    public function isCadUnavailable(): bool
    {
        return $this->cadUnavailable;
    }

    public function isPriceExpirationCheck(): bool
    {
        return $this->priceExpirationCheck;
    }

    public function getPriceExpirationDate(): ?\DateTimeImmutable
    {
        return $this->priceExpirationDate;
    }

    public function getPriceExpirationJudgment(): int
    {
        return $this->priceExpirationJudgment;
    }

    public function getMinimumSalesQuantity(): ?int
    {
        return $this->minimumSalesQuantity;
    }

    public function getSortNo(): int
    {
        return $this->sortNo;
    }

    public function getProductStatusId(): ?int
    {
        return $this->productStatusId;
    }

    public function getDisplayStatusId(): ?int
    {
        return $this->displayStatusId;
    }

    public function getSaleTypeId(): ?int
    {
        return $this->saleTypeId;
    }

    public function getCreateDate(): \DateTimeImmutable
    {
        return $this->createDate;
    }

    public function getUpdateDate(): \DateTimeImmutable
    {
        return $this->updateDate;
    }
}
