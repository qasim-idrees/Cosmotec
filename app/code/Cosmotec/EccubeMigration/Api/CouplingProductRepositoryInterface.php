<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Api;

use Cosmotec\EccubeMigration\Api\Data\CouplingProductInterface;
use Cosmotec\EccubeMigration\Model\Connection\EccubeConnectionException;

/**
 * Read-only access to dtb_coupling_product.
 */
interface CouplingProductRepositoryInterface
{
    /**
     * @return CouplingProductInterface[]
     * @throws EccubeConnectionException
     */
    public function getBatch(int $offset, int $limit): array;

    /**
     * @return CouplingProductInterface[]
     * @throws EccubeConnectionException
     */
    public function getByItemId(int $itemId): array;

    /**
     * @throws EccubeConnectionException
     */
    public function countAll(): int;
}
