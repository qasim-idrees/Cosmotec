<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Repository;

use Cosmotec\EccubeMigration\Api\ProductMapRepositoryInterface;
use Cosmotec\EccubeMigration\Model\ProductMap;
use Cosmotec\EccubeMigration\Model\ProductMapFactory;
use Cosmotec\EccubeMigration\Model\ResourceModel\ProductMap as ProductMapResource;
use Cosmotec\EccubeMigration\Model\ResourceModel\ProductMap\CollectionFactory as ProductMapCollectionFactory;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\CouldNotSaveException;

class ProductMapRepository implements ProductMapRepositoryInterface
{
    public function __construct(
        private readonly ProductMapResource $resource,
        private readonly ProductMapFactory $productMapFactory,
        private readonly ProductMapCollectionFactory $collectionFactory
    ) {
    }

    public function getByEccubeProductId(int $eccubeProductId): ?ProductMap
    {
        /** @var ProductMap $map */
        $map = $this->productMapFactory->create();
        $this->resource->load($map, $eccubeProductId, 'eccube_product_id');

        return $map->getId() === null ? null : $map;
    }

    public function save(ProductMap $productMap): ProductMap
    {
        try {
            $this->resource->save($productMap);
        } catch (AlreadyExistsException $e) {
            throw new CouldNotSaveException(
                __('Could not save product map for EC-CUBE product %1: %2', $productMap->getEccubeProductId(), $e->getMessage()),
                $e
            );
        }

        return $productMap;
    }

    public function getUnfinished(int $limit): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('status', ['in' => [ProductMap::STATUS_PENDING, ProductMap::STATUS_ERROR]]);
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

    public function getUnlinkedByItemId(int $eccubeItemId): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('eccube_item_id', $eccubeItemId);
        $collection->addFieldToFilter('status', ['in' => [ProductMap::STATUS_IMPORTED, ProductMap::STATUS_UPDATED]]);
        $collection->addFieldToFilter('relation_linked', 0);

        return array_values($collection->getItems());
    }

    public function getBySku(string $sku): ?ProductMap
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('sku', $sku);
        $collection->setPageSize(1);
        $collection->setCurPage(1);

        $items = array_values($collection->getItems());

        return $items[0] ?? null;
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
