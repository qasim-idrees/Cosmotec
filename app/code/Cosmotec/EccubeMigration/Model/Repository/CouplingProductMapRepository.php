<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Repository;

use Cosmotec\EccubeMigration\Api\CouplingProductMapRepositoryInterface;
use Cosmotec\EccubeMigration\Model\CouplingProductMap;
use Cosmotec\EccubeMigration\Model\CouplingProductMapFactory;
use Cosmotec\EccubeMigration\Model\ResourceModel\CouplingProductMap as CouplingProductMapResource;
use Cosmotec\EccubeMigration\Model\ResourceModel\CouplingProductMap\CollectionFactory;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\CouldNotSaveException;

class CouplingProductMapRepository implements CouplingProductMapRepositoryInterface
{
    public function __construct(
        private readonly CouplingProductMapResource $resource,
        private readonly CouplingProductMapFactory $mapFactory,
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    public function getByCouplingId(int $eccubeCouplingId): ?CouplingProductMap
    {
        /** @var CouplingProductMap $map */
        $map = $this->mapFactory->create();
        $this->resource->load($map, $eccubeCouplingId, 'eccube_coupling_id');

        return $map->getId() === null ? null : $map;
    }

    public function save(CouplingProductMap $map): CouplingProductMap
    {
        try {
            $this->resource->save($map);
        } catch (AlreadyExistsException $e) {
            throw new CouldNotSaveException(
                __('Could not save coupling product map for coupling %1: %2', $map->getEccubeCouplingId(), $e->getMessage()),
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

    public function getFailed(int $limit): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('status', [
            'in' => [CouplingProductMap::STATUS_ERROR, CouplingProductMap::STATUS_PENDING],
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

    public function getDistinctItemIds(int $offset, int $limit): array
    {
        $collection = $this->collectionFactory->create();
        $collection->getSelect()->reset(\Magento\Framework\DB\Select::COLUMNS)
            ->distinct(true)
            ->columns('eccube_item_id')
            ->order('eccube_item_id ASC')
            ->limit($limit, $offset);

        return array_map('intval', $collection->getConnection()->fetchCol($collection->getSelect()));
    }
}
