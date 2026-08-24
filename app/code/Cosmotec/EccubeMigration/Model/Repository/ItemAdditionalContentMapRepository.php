<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Repository;

use Cosmotec\EccubeMigration\Api\ItemAdditionalContentMapRepositoryInterface;
use Cosmotec\EccubeMigration\Model\ItemAdditionalContentMap;
use Cosmotec\EccubeMigration\Model\ItemAdditionalContentMapFactory;
use Cosmotec\EccubeMigration\Model\ResourceModel\ItemAdditionalContentMap as ItemAdditionalContentMapResource;
use Cosmotec\EccubeMigration\Model\ResourceModel\ItemAdditionalContentMap\CollectionFactory;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\CouldNotSaveException;

class ItemAdditionalContentMapRepository implements ItemAdditionalContentMapRepositoryInterface
{
    public function __construct(
        private readonly ItemAdditionalContentMapResource $resource,
        private readonly ItemAdditionalContentMapFactory $mapFactory,
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    public function getBySourceRowId(int $eccubeAdditionalInformationId): ?ItemAdditionalContentMap
    {
        /** @var ItemAdditionalContentMap $map */
        $map = $this->mapFactory->create();
        $this->resource->load($map, $eccubeAdditionalInformationId, 'eccube_additional_information_id');

        return $map->getId() === null ? null : $map;
    }

    public function save(ItemAdditionalContentMap $map): ItemAdditionalContentMap
    {
        try {
            $this->resource->save($map);
        } catch (AlreadyExistsException $e) {
            throw new CouldNotSaveException(
                __('Could not save item additional content map for source row %1: %2', $map->getEccubeAdditionalInformationId(), $e->getMessage()),
                $e
            );
        }

        return $map;
    }

    public function getByItemId(int $eccubeItemId): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('eccube_item_id', $eccubeItemId);
        $collection->setOrder('sort_no', 'ASC');

        return array_values($collection->getItems());
    }

    public function getByMagentoProductId(int $magentoProductId): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('magento_product_id', $magentoProductId);
        $collection->addFieldToFilter('status', ['neq' => ItemAdditionalContentMap::STATUS_OBSOLETE]);
        $collection->setOrder('sort_no', 'ASC');

        return array_values($collection->getItems());
    }

    public function countByStatus(string $status): int
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('status', $status);

        return $collection->getSize();
    }
}
