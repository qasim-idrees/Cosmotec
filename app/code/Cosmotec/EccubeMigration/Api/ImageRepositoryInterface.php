<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Api;

use Cosmotec\EccubeMigration\Api\Data\ImageInterface;
use Cosmotec\EccubeMigration\Model\Connection\EccubeConnectionException;

interface ImageRepositoryInterface
{
    /**
     * Ordered by sort_no ascending.
     *
     * @return ImageInterface[]
     * @throws EccubeConnectionException
     */
    public function getByProductId(int $productId): array;

    /**
     * Global page over dtb_product_image, ordered by id ascending. Used by
     * ImageReader for a full/incremental image pass independent of any
     * single product.
     *
     * @return ImageInterface[]
     * @throws EccubeConnectionException
     */
    public function getBatch(int $offset, int $limit): array;

    /**
     * @throws EccubeConnectionException
     */
    public function countAll(): int;
}
