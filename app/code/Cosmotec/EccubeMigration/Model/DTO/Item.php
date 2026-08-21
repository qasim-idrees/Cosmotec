<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\DTO;

use Cosmotec\EccubeMigration\Api\Data\ItemInterface;

final class Item implements ItemInterface
{
    public function __construct(
        private readonly int $id,
        private readonly string $name,
        private readonly string $nameEn,
        private readonly string $shortName,
        private readonly string $shortNameEn,
        private readonly ?string $description,
        private readonly ?string $descriptionEn,
        private readonly ?int $displayStatusId
    ) {
    }

    public function getId(): int
    {
        return $this->id;
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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getDescriptionEn(): ?string
    {
        return $this->descriptionEn;
    }

    public function getDisplayStatusId(): ?int
    {
        return $this->displayStatusId;
    }
}
