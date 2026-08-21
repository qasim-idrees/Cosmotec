<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Api;

use Cosmotec\EccubeMigration\Model\ImageMap;
use Magento\Framework\Exception\CouldNotSaveException;

interface ImageMapRepositoryInterface
{
    public function getByEccubeImageId(int $eccubeImageId): ?ImageMap;

    /**
     * @throws CouldNotSaveException
     */
    public function save(ImageMap $imageMap): ImageMap;

    /**
     * @return ImageMap[]
     */
    public function getUnfinished(int $limit): array;

    public function countByStatus(string $status): int;

    /**
     * True if a successfully-imported image with is_main=1 already exists
     * for this product — determines whether the next image processed gets
     * the base/small_image/thumbnail roles or is gallery-only.
     */
    public function hasMainImage(int $eccubeProductId): bool;
}
