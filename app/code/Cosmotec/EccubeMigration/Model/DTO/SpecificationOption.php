<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\DTO;

use Cosmotec\EccubeMigration\Api\Data\SpecificationOptionInterface;

final class SpecificationOption implements SpecificationOptionInterface
{
    public function __construct(
        private readonly int $id,
        private readonly int $specificationId,
        private readonly string $name,
        private readonly string $nameEn,
        private readonly int $sortNo
    ) {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getSpecificationId(): int
    {
        return $this->specificationId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getNameEn(): string
    {
        return $this->nameEn;
    }

    public function getLabel(): string
    {
        $en = trim($this->nameEn);

        return $en !== '' ? $en : trim($this->name);
    }

    public function getSortNo(): int
    {
        return $this->sortNo;
    }
}
