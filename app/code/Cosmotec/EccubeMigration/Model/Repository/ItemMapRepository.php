<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Repository;

use Cosmotec\EccubeMigration\Api\ItemMapRepositoryInterface;
use Cosmotec\EccubeMigration\Model\ItemMap;
use Cosmotec\EccubeMigration\Model\ItemMapFactory;
use Cosmotec\EccubeMigration\Model\ResourceModel\ItemMap as ItemMapResource;
use Cosmotec\EccubeMigration\Model\ResourceModel\ItemMap\CollectionFactory as ItemMapCollectionFactory;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\CouldNotSaveException;

class ItemMapRepository implements ItemMapRepositoryInterface
{
    public function __construct(
        private readonly ItemMapResource $resource,
        private readonly ItemMapFactory $itemMapFactory,
        private readonly ItemMapCollectionFactory $collectionFactory
    ) {
    }

    public function getByEccubeItemId(int $eccubeItemId): ?ItemMap
    {
        /** @var ItemMap $map */
        $map = $this->itemMapFactory->create();
        $this->resource->load($map, $eccubeItemId, 'eccube_item_id');

        return $map->getId() === null ? null : $map;
    }

    public function save(ItemMap $itemMap): ItemMap
    {
        try {
            $this->resource->save($itemMap);
        } catch (AlreadyExistsException $e) {
            throw new CouldNotSaveException(
                __('Could not save item map for EC-CUBE item %1: %2', $itemMap->getEccubeItemId(), $e->getMessage()),
                $e
            );
        }

        return $itemMap;
    }

    public function getUnfinished(int $limit): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('status', ['in' => [ItemMap::STATUS_PENDING, ItemMap::STATUS_ERROR]]);
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

    public function getMappedBatch(int $offset, int $limit): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('status', ['in' => [ItemMap::STATUS_IMPORTED, ItemMap::STATUS_UPDATED]]);
        $collection->addFieldToFilter('magento_product_id', ['notnull' => true]);
        $collection->setOrder('eccube_item_id', 'ASC');
        $collection->setPageSize(max(1, $limit));
        $collection->setCurPage((int) floor($offset / max(1, $limit)) + 1);

        return array_values($collection->getItems());
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
