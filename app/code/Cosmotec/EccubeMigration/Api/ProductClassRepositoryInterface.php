<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Api;

use Cosmotec\EccubeMigration\Api\Data\ProductClassInterface;
use Cosmotec\EccubeMigration\Model\Connection\EccubeConnectionException;

interface ProductClassRepositoryInterface
{
    /**
     * @return ProductClassInterface[]
     * @throws EccubeConnectionException
     */
    public function getByProductId(int $productId): array;
}
