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
 * Maps 1:1 to dtb_category. This is EC-CUBE-side source data; the
 * Milestone 3 Mapper layer converts it into the Magento-target category DTO.
 */
interface CategoryInterface
{
    public function getId(): int;

    public function getParentCategoryId(): ?int;

    public function getCategoryName(): string;

    public function getCategoryNameEn(): string;

    public function getShortName(): string;

    public function getShortNameEn(): string;

    public function getDescription(): ?string;

    public function getDescriptionEn(): ?string;

    public function getHierarchy(): int;

    public function getSortNo(): int;

    public function getCreateDate(): \DateTimeImmutable;

    public function getUpdateDate(): \DateTimeImmutable;
}
