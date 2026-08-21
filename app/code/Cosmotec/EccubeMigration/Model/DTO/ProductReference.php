<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\DTO;

use Cosmotec\EccubeMigration\Api\Data\ProductReferenceInterface;

final class ProductReference implements ProductReferenceInterface
{
    private readonly string $contentHash;

    public function __construct(
        private readonly int $id,
        private readonly int $productId,
        private readonly ?string $name,
        private readonly ?string $link
    ) {
        $this->contentHash = hash('sha256', ($this->name ?? '') . '|' . ($this->link ?? ''));
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getProductId(): int
    {
        return $this->productId;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function getLink(): ?string
    {
        return $this->link;
    }

    public function getContentHash(): string
    {
        return $this->contentHash;
    }
}
