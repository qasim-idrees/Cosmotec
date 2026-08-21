<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Api;

use Cosmotec\EccubeMigration\Api\Data\ProductReferenceInterface;
use Cosmotec\EccubeMigration\Model\Connection\EccubeConnectionException;

/**
 * Read-only access to dtb_product_reference (1:N document name/link).
 */
interface ProductReferenceRepositoryInterface
{
    /**
     * @return ProductReferenceInterface[]
     * @throws EccubeConnectionException
     */
    public function getBatch(int $offset, int $limit): array;

    /**
     * @return ProductReferenceInterface[]
     * @throws EccubeConnectionException
     */
    public function getByProductId(int $productId): array;

    /**
     * @throws EccubeConnectionException
     */
    public function countAll(): int;
}
