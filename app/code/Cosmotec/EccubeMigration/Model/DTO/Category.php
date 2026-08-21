<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\DTO;

use Cosmotec\EccubeMigration\Api\Data\CategoryInterface;

final class Category implements CategoryInterface
{
    public function __construct(
        private readonly int $id,
        private readonly ?int $parentCategoryId,
        private readonly string $categoryName,
        private readonly string $categoryNameEn,
        private readonly string $shortName,
        private readonly string $shortNameEn,
        private readonly ?string $description,
        private readonly ?string $descriptionEn,
        private readonly int $hierarchy,
        private readonly int $sortNo,
        private readonly \DateTimeImmutable $createDate,
        private readonly \DateTimeImmutable $updateDate
    ) {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getParentCategoryId(): ?int
    {
        return $this->parentCategoryId;
    }

    public function getCategoryName(): string
    {
        return $this->categoryName;
    }

    public function getCategoryNameEn(): string
    {
        return $this->categoryNameEn;
    }

    public function getShortName(): string
    {
        return $this->shortName;
    }

    public function getShortNameEn(): string
    {
        return $this->shortNameEn;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getDescriptionEn(): ?string
    {
        return $this->descriptionEn;
    }

    public function getHierarchy(): int
    {
        return $this->hierarchy;
    }

    public function getSortNo(): int
    {
        return $this->sortNo;
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
