<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\DTO;

use Cosmotec\EccubeMigration\Api\Data\ItemSpecificationValueInterface;

final class ItemSpecificationValue implements ItemSpecificationValueInterface
{
    public function __construct(
        private readonly int $id,
        private readonly int $itemId,
        private readonly int $specificationId,
        private readonly int $specificationClassId,
        private readonly int $sortNo,
        private readonly bool $selectable
    ) {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getItemId(): int
    {
        return $this->itemId;
    }

    public function getSpecificationId(): int
    {
        return $this->specificationId;
    }

    public function getSpecificationClassId(): int
    {
        return $this->specificationClassId;
    }

    public function getSortNo(): int
    {
        return $this->sortNo;
    }

    public function isSelectable(): bool
    {
        return $this->selectable;
    }
}
