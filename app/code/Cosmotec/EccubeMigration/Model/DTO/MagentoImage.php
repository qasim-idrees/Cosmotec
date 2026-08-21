<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\DTO;

use Cosmotec\EccubeMigration\Api\Data\MagentoImageInterface;

final class MagentoImage implements MagentoImageInterface
{
    public function __construct(
        private readonly int $eccubeImageId,
        private readonly int $eccubeProductId,
        private readonly string $absolutePath,
        private readonly string $fileName,
        private readonly bool $main,
        private readonly int $position,
        private readonly string $contentHash
    ) {
    }

    public function getEccubeImageId(): int
    {
        return $this->eccubeImageId;
    }

    public function getEccubeProductId(): int
    {
        return $this->eccubeProductId;
    }

    public function getAbsolutePath(): string
    {
        return $this->absolutePath;
    }

    public function getFileName(): string
    {
        return $this->fileName;
    }

    public function isMain(): bool
    {
        return $this->main;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getContentHash(): string
    {
        return $this->contentHash;
    }
}
