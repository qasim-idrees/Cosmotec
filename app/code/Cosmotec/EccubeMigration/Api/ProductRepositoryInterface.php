<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Api;

use Cosmotec\EccubeMigration\Api\Data\ProductInterface;
use Cosmotec\EccubeMigration\Model\Connection\EccubeConnectionException;

interface ProductRepositoryInterface
{
    /**
     * @throws EccubeConnectionException
     */
    public function getById(int $id): ?ProductInterface;

    /**
     * @return ProductInterface[]
     * @throws EccubeConnectionException
     */
    public function getBatch(int $offset, int $limit): array;

    /**
     * @return ProductInterface[]
     * @throws EccubeConnectionException
     */
    public function getByItemId(int $itemId): array;

    /**
     * @throws EccubeConnectionException
     */
    public function countAll(): int;

    /**
     * @return ProductInterface[]
     * @throws EccubeConnectionException
     */
    public function getModifiedSince(\DateTimeInterface $since, int $offset, int $limit): array;
}
