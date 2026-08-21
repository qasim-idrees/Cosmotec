<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Mapper;

use Cosmotec\EccubeMigration\Api\Data\ImageInterface;
use Cosmotec\EccubeMigration\Api\Data\MagentoImageInterface;
use Cosmotec\EccubeMigration\Api\EccubeConfigProviderInterface;
use Cosmotec\EccubeMigration\Api\ImageMapRepositoryInterface;
use Cosmotec\EccubeMigration\Model\DTO\MagentoImage;

class ImageMapper implements MapperInterface
{
    public function __construct(
        private readonly EccubeConfigProviderInterface $config,
        private readonly ImageMapRepositoryInterface $imageMapRepository
    ) {
    }

    /**
     * @param ImageInterface $source
     */
    public function map(object $source): MagentoImageInterface
    {
        if (!$source instanceof ImageInterface) {
            throw new \InvalidArgumentException(sprintf(
                'ImageMapper expects %s, got %s',
                ImageInterface::class,
                get_debug_type($source)
            ));
        }

        $imageFolder = $this->config->getImageFolder();

        if ($imageFolder === null) {
            throw new \RuntimeException('EC-CUBE Image Folder Path is not configured.');
        }

        $absolutePath = rtrim($imageFolder, '/') . '/' . ltrim($source->getFileName(), '/');

        if (!is_file($absolutePath)) {
            throw new \RuntimeException(sprintf('File "%s" does not exist.', $absolutePath));
        }

        $productId = (int) $source->getProductId();
        $isMain = !$this->imageMapRepository->hasMainImage($productId);

        return new MagentoImage(
            $source->getId(),
            $productId,
            $absolutePath,
            $source->getFileName(),
            $isMain,
            $source->getSortNo(),
            $this->computeContentHash($absolutePath, $source->getFileName())
        );
    }

    /**
     * Filename + filesize + mtime, not full file content: cheap enough to
     * recompute on every run for potentially thousands of images, while
     * still catching the common "file was replaced" case. A file replaced
     * with different content but identical size and mtime (extremely
     * unlikely for CMS-managed uploads) would not be detected as changed;
     * documented here rather than silently assumed.
     */
    private function computeContentHash(string $absolutePath, string $fileName): string
    {
        $size = filesize($absolutePath);
        $mtime = filemtime($absolutePath);

        return hash('sha256', $fileName . '|' . ($size !== false ? $size : '0') . '|' . ($mtime !== false ? $mtime : '0'));
    }
}
