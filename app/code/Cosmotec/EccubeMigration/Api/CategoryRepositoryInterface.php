<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Api;

use Cosmotec\EccubeMigration\Api\Data\CategoryInterface;
use Cosmotec\EccubeMigration\Model\Connection\EccubeConnectionException;

interface CategoryRepositoryInterface
{
    /**
     * @throws EccubeConnectionException
     */
    public function getById(int $id): ?CategoryInterface;

    /**
     * @return CategoryInterface[]
     * @throws EccubeConnectionException
     */
    public function getBatch(int $offset, int $limit): array;

    /**
     * @throws EccubeConnectionException
     */
    public function countAll(): int;

    /**
     * @return CategoryInterface[]
     * @throws EccubeConnectionException
     */
    public function getModifiedSince(\DateTimeInterface $since, int $offset, int $limit): array;

    /**
     * Item IDs directly attached to a category via dtb_category_item.
     *
     * @return int[]
     * @throws EccubeConnectionException
     */
    public function getItemIdsByCategoryId(int $categoryId): array;
}
