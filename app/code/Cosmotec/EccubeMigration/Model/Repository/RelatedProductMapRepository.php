<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Repository;

use Cosmotec\EccubeMigration\Api\RelatedProductMapRepositoryInterface;
use Cosmotec\EccubeMigration\Model\RelatedProductMap;
use Cosmotec\EccubeMigration\Model\RelatedProductMapFactory;
use Cosmotec\EccubeMigration\Model\ResourceModel\RelatedProductMap as RelatedProductMapResource;
use Cosmotec\EccubeMigration\Model\ResourceModel\RelatedProductMap\CollectionFactory;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\CouldNotSaveException;

class RelatedProductMapRepository implements RelatedProductMapRepositoryInterface
{
    public function __construct(
        private readonly RelatedProductMapResource $resource,
        private readonly RelatedProductMapFactory $mapFactory,
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    public function getByRelatedId(int $eccubeRelatedId): ?RelatedProductMap
    {
        /** @var RelatedProductMap $map */
        $map = $this->mapFactory->create();
        $this->resource->load($map, $eccubeRelatedId, 'eccube_related_id');

        return $map->getId() === null ? null : $map;
    }

    public function save(RelatedProductMap $map): RelatedProductMap
    {
        try {
            $this->resource->save($map);
        } catch (AlreadyExistsException $e) {
            throw new CouldNotSaveException(
                __('Could not save related product map for related row %1: %2', $map->getEccubeRelatedId(), $e->getMessage()),
                $e
            );
        }

        return $map;
    }

    public function getByProductId(int $eccubeProductId): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('eccube_product_id', $eccubeProductId);
        $collection->setOrder('sort_no', 'ASC');

        return array_values($collection->getItems());
    }

    public function getFailed(int $limit): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('status', [
            'in' => [RelatedProductMap::STATUS_ERROR, RelatedProductMap::STATUS_PENDING],
        ]);
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

    public function getDistinctProductIds(int $offset, int $limit): array
    {
        $collection = $this->collectionFactory->create();
        $collection->getSelect()->reset(\Magento\Framework\DB\Select::COLUMNS)
            ->distinct(true)
            ->columns('eccube_product_id')
            ->order('eccube_product_id ASC')
            ->limit($limit, $offset);

        return array_map('intval', $collection->getConnection()->fetchCol($collection->getSelect()));
    }
}
