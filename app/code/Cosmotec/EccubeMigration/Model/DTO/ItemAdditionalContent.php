<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\DTO;

use Cosmotec\EccubeMigration\Api\Data\ItemAdditionalContentInterface;

final class ItemAdditionalContent implements ItemAdditionalContentInterface
{
    private readonly string $contentHash;

    public function __construct(
        private readonly int $id,
        private readonly int $itemId,
        private readonly ?string $tabNameEn,
        private readonly ?string $tabNameJa,
        private readonly ?string $htmlContent
    ) {
        $this->contentHash = hash('sha256', implode('|', [
            $this->tabNameEn ?? '',
            $this->tabNameJa ?? '',
            $this->htmlContent ?? '',
        ]));
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getItemId(): int
    {
        return $this->itemId;
    }

    public function getTabNameEn(): ?string
    {
        return $this->tabNameEn;
    }

    public function getTabNameJa(): ?string
    {
        return $this->tabNameJa;
    }

    public function getHtmlContent(): ?string
    {
        return $this->htmlContent;
    }

    public function getContentHash(): string
    {
        return $this->contentHash;
    }
}
