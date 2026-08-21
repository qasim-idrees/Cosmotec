<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Repository;

use Cosmotec\EccubeMigration\Api\CategoryMapRepositoryInterface;
use Cosmotec\EccubeMigration\Model\CategoryMap;
use Cosmotec\EccubeMigration\Model\CategoryMapFactory;
use Cosmotec\EccubeMigration\Model\ResourceModel\CategoryMap as CategoryMapResource;
use Cosmotec\EccubeMigration\Model\ResourceModel\CategoryMap\CollectionFactory as CategoryMapCollectionFactory;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\CouldNotSaveException;

class CategoryMapRepository implements CategoryMapRepositoryInterface
{
    public function __construct(
        private readonly CategoryMapResource $resource,
        private readonly CategoryMapFactory $categoryMapFactory,
        private readonly CategoryMapCollectionFactory $collectionFactory
    ) {
    }

    public function getByEccubeCategoryId(int $eccubeCategoryId): ?CategoryMap
    {
        /** @var CategoryMap $map */
        $map = $this->categoryMapFactory->create();
        $this->resource->load($map, $eccubeCategoryId, 'eccube_category_id');

        return $map->getId() === null ? null : $map;
    }

    public function save(CategoryMap $categoryMap): CategoryMap
    {
        try {
            $this->resource->save($categoryMap);
        } catch (AlreadyExistsException $e) {
            throw new CouldNotSaveException(
                __('Could not save category map for EC-CUBE category %1: %2', $categoryMap->getEccubeCategoryId(), $e->getMessage()),
                $e
            );
        }

        return $categoryMap;
    }

    public function getUnfinished(int $limit): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter(
            'status',
            ['in' => [CategoryMap::STATUS_PENDING, CategoryMap::STATUS_ERROR]]
        );
        $collection->setPageSize($limit);
        $collection->setCurPage(1);

        return array_values($collection->getItems());
    }

    public function countByStatus(string $status): int
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('status', $status);

        return $collection->getSize();
    }

    public function getMaxLastSyncedAt(): ?\DateTimeImmutable
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('last_synced_at', ['notnull' => true]);
        $collection->setOrder('last_synced_at', 'DESC');
        $collection->setPageSize(1);
        $collection->setCurPage(1);

        $items = array_values($collection->getItems());
        $value = $items[0]->getLastSyncedAt() ?? null;

        return $value !== null ? new \DateTimeImmutable($value) : null;
    }
}
