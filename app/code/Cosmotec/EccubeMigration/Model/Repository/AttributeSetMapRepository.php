<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Repository;

use Cosmotec\EccubeMigration\Api\AttributeSetMapRepositoryInterface;
use Cosmotec\EccubeMigration\Model\AttributeSetMap;
use Cosmotec\EccubeMigration\Model\AttributeSetMapFactory;
use Cosmotec\EccubeMigration\Model\ResourceModel\AttributeSetMap as AttributeSetMapResource;
use Cosmotec\EccubeMigration\Model\ResourceModel\AttributeSetMap\CollectionFactory;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\CouldNotSaveException;

class AttributeSetMapRepository implements AttributeSetMapRepositoryInterface
{
    public function __construct(
        private readonly AttributeSetMapResource $resource,
        private readonly AttributeSetMapFactory $mapFactory,
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    public function getByTopLevelCategoryId(int $eccubeCategoryId): ?AttributeSetMap
    {
        /** @var AttributeSetMap $map */
        $map = $this->mapFactory->create();
        $this->resource->load($map, $eccubeCategoryId, 'eccube_top_level_category_id');

        return $map->getId() === null ? null : $map;
    }

    public function save(AttributeSetMap $map): AttributeSetMap
    {
        try {
            $this->resource->save($map);
        } catch (AlreadyExistsException $e) {
            throw new CouldNotSaveException(
                __(
                    'Could not save attribute set map for top-level category %1: %2',
                    $map->getEccubeTopLevelCategoryId(),
                    $e->getMessage()
                ),
                $e
            );
        }

        return $map;
    }

    public function getAll(): array
    {
        $collection = $this->collectionFactory->create();

        return array_values($collection->getItems());
    }

    public function countByStatus(string $status): int
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('status', $status);

        return $collection->getSize();
    }
}
