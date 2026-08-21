<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\DTO;

use Cosmotec\EccubeMigration\Api\Data\MagentoParentProductInterface;

final class MagentoParentProduct implements MagentoParentProductInterface
{
    private readonly string $contentHash;

    /**
     * @param int[] $categoryIds
     */
    public function __construct(
        private readonly int $eccubeItemId,
        private readonly string $sku,
        private readonly string $name,
        private readonly string $typeId,
        private readonly bool $enabled,
        private readonly int $visibility,
        private readonly int $attributeSetId,
        private readonly array $categoryIds
    ) {
        $sortedCategoryIds = $this->categoryIds;
        sort($sortedCategoryIds);

        $this->contentHash = hash('sha256', implode('|', [
            $this->sku,
            $this->name,
            $this->typeId,
            $this->enabled ? '1' : '0',
            $this->visibility,
            $this->attributeSetId,
            implode(',', $sortedCategoryIds),
        ]));
    }

    public function getEccubeItemId(): int
    {
        return $this->eccubeItemId;
    }

    public function getSku(): string
    {
        return $this->sku;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getTypeId(): string
    {
        return $this->typeId;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getVisibility(): int
    {
        return $this->visibility;
    }

    public function getAttributeSetId(): int
    {
        return $this->attributeSetId;
    }

    public function getCategoryIds(): array
    {
        return $this->categoryIds;
    }

    public function getContentHash(): string
    {
        return $this->contentHash;
    }
}
