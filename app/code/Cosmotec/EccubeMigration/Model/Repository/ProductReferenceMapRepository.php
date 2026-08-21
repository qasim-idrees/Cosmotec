<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Repository;

use Cosmotec\EccubeMigration\Api\ProductReferenceMapRepositoryInterface;
use Cosmotec\EccubeMigration\Model\ProductReferenceMap;
use Cosmotec\EccubeMigration\Model\ProductReferenceMapFactory;
use Cosmotec\EccubeMigration\Model\ResourceModel\ProductReferenceMap as ProductReferenceMapResource;
use Cosmotec\EccubeMigration\Model\ResourceModel\ProductReferenceMap\CollectionFactory;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\CouldNotSaveException;

class ProductReferenceMapRepository implements ProductReferenceMapRepositoryInterface
{
    public function __construct(
        private readonly ProductReferenceMapResource $resource,
        private readonly ProductReferenceMapFactory $mapFactory,
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    public function getByReferenceId(int $eccubeReferenceId): ?ProductReferenceMap
    {
        /** @var ProductReferenceMap $map */
        $map = $this->mapFactory->create();
        $this->resource->load($map, $eccubeReferenceId, 'eccube_reference_id');

        return $map->getId() === null ? null : $map;
    }

    public function save(ProductReferenceMap $map): ProductReferenceMap
    {
        try {
            $this->resource->save($map);
        } catch (AlreadyExistsException $e) {
            throw new CouldNotSaveException(
                __('Could not save product reference map for reference %1: %2', $map->getEccubeReferenceId(), $e->getMessage()),
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
            'in' => [ProductReferenceMap::STATUS_ERROR, ProductReferenceMap::STATUS_PENDING],
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
}
