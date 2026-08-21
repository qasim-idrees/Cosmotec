<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\DTO;

use Cosmotec\EccubeMigration\Api\Data\MagentoCategoryInterface;

final class MagentoCategory implements MagentoCategoryInterface
{
    private readonly string $contentHash;

    public function __construct(
        private readonly int $eccubeCategoryId,
        private readonly string $name,
        private readonly int $magentoParentId,
        private readonly bool $active,
        private readonly bool $includeInMenu,
        private readonly int $position,
        private readonly ?string $description,
        private readonly ?string $urlKey
    ) {
        $this->contentHash = hash('sha256', implode('|', [
            $this->name,
            $this->magentoParentId,
            $this->active ? '1' : '0',
            $this->includeInMenu ? '1' : '0',
            $this->position,
            $this->description ?? '',
            $this->urlKey ?? '',
        ]));
    }

    public function getEccubeCategoryId(): int
    {
        return $this->eccubeCategoryId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getMagentoParentId(): int
    {
        return $this->magentoParentId;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function isIncludeInMenu(): bool
    {
        return $this->includeInMenu;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getUrlKey(): ?string
    {
        return $this->urlKey;
    }

    public function getContentHash(): string
    {
        return $this->contentHash;
    }
}
