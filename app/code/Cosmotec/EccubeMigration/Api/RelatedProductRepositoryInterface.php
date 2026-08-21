<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Api;

use Cosmotec\EccubeMigration\Api\Data\RelatedProductInterface;
use Cosmotec\EccubeMigration\Model\Connection\EccubeConnectionException;

/**
 * Read-only access to dtb_related_product.
 */
interface RelatedProductRepositoryInterface
{
    /**
     * @return RelatedProductInterface[]
     * @throws EccubeConnectionException
     */
    public function getBatch(int $offset, int $limit): array;

    /**
     * @return RelatedProductInterface[]
     * @throws EccubeConnectionException
     */
    public function getByProductId(int $productId): array;

    /**
     * @throws EccubeConnectionException
     */
    public function countAll(): int;
}
