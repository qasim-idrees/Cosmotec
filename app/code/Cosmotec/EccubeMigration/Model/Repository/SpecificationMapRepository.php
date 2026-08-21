<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Repository;

use Cosmotec\EccubeMigration\Api\SpecificationMapRepositoryInterface;
use Cosmotec\EccubeMigration\Model\ResourceModel\SpecificationMap as SpecificationMapResource;
use Cosmotec\EccubeMigration\Model\ResourceModel\SpecificationMap\CollectionFactory as SpecificationMapCollectionFactory;
use Cosmotec\EccubeMigration\Model\ResourceModel\SpecificationOptionMap as SpecificationOptionMapResource;
use Cosmotec\EccubeMigration\Model\ResourceModel\SpecificationOptionMap\CollectionFactory as SpecificationOptionMapCollectionFactory;
use Cosmotec\EccubeMigration\Model\SpecificationMap;
use Cosmotec\EccubeMigration\Model\SpecificationMapFactory;
use Cosmotec\EccubeMigration\Model\SpecificationOptionMap;
use Cosmotec\EccubeMigration\Model\SpecificationOptionMapFactory;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\CouldNotSaveException;

class SpecificationMapRepository implements SpecificationMapRepositoryInterface
{
    public function __construct(
        private readonly SpecificationMapResource $resource,
        private readonly SpecificationMapFactory $mapFactory,
        private readonly SpecificationMapCollectionFactory $collectionFactory,
        private readonly SpecificationOptionMapResource $optionResource,
        private readonly SpecificationOptionMapFactory $optionMapFactory,
        private readonly SpecificationOptionMapCollectionFactory $optionCollectionFactory
    ) {
    }

    public function getBySpecificationId(int $eccubeSpecificationId): ?SpecificationMap
    {
        /** @var SpecificationMap $map */
        $map = $this->mapFactory->create();
        $this->resource->load($map, $eccubeSpecificationId, 'eccube_specification_id');

        return $map->getId() === null ? null : $map;
    }

    public function save(SpecificationMap $map): SpecificationMap
    {
        try {
            $this->resource->save($map);
        } catch (AlreadyExistsException $e) {
            throw new CouldNotSaveException(
                __('Could not save specification map for EC-CUBE specification %1: %2', $map->getEccubeSpecificationId(), $e->getMessage()),
                $e
            );
        }

        return $map;
    }

    public function countByStatus(string $status): int
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('status', $status);

        return $collection->getSize();
    }

    public function getOptionByClassId(int $eccubeSpecificationClassId): ?SpecificationOptionMap
    {
        /** @var SpecificationOptionMap $map */
        $map = $this->optionMapFactory->create();
        $this->optionResource->load($map, $eccubeSpecificationClassId, 'eccube_specification_class_id');

        return $map->getId() === null ? null : $map;
    }

    public function saveOption(SpecificationOptionMap $map): SpecificationOptionMap
    {
        try {
            $this->optionResource->save($map);
        } catch (AlreadyExistsException $e) {
            throw new CouldNotSaveException(
                __('Could not save option map for EC-CUBE specification class %1: %2', $map->getEccubeSpecificationClassId(), $e->getMessage()),
                $e
            );
        }

        return $map;
    }

    public function getOptionsBySpecificationId(int $eccubeSpecificationId): array
    {
        $collection = $this->optionCollectionFactory->create();
        $collection->addFieldToFilter('eccube_specification_id', $eccubeSpecificationId);
        $collection->setOrder('sort_no', 'ASC');

        return array_values($collection->getItems());
    }
}
