<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Repository;

use Cosmotec\EccubeMigration\Api\CategoryInheritanceMapRepositoryInterface;
use Cosmotec\EccubeMigration\Model\ResourceModel\CategoryInheritanceMap as CategoryInheritanceMapResource;

class CategoryInheritanceMapRepository implements CategoryInheritanceMapRepositoryInterface
{
    public function __construct(
        private readonly CategoryInheritanceMapResource $resource
    ) {
    }

    public function getCategoryIdsForProduct(int $magentoProductId): array
    {
        return $this->resource->getCategoryIdsForProduct($magentoProductId);
    }

    public function replaceForProduct(int $magentoProductId, int $eccubeItemId, array $categoryIds): void
    {
        $this->resource->replaceForProduct($magentoProductId, $eccubeItemId, $categoryIds);
    }
}
