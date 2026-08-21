<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Repository;

use Cosmotec\EccubeMigration\Api\ProductSpecificationValueMapRepositoryInterface;
use Cosmotec\EccubeMigration\Model\ProductSpecificationValueMap;
use Cosmotec\EccubeMigration\Model\ProductSpecificationValueMapFactory;
use Cosmotec\EccubeMigration\Model\ResourceModel\ProductSpecificationValueMap as ProductSpecificationValueMapResource;
use Cosmotec\EccubeMigration\Model\ResourceModel\ProductSpecificationValueMap\CollectionFactory;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\CouldNotSaveException;

/**
 * PENDING DESIGN - see Model\Specification\MultiValueSpecificationRegistry
 * and BUILD_STATUS.md.
 */
class ProductSpecificationValueMapRepository implements ProductSpecificationValueMapRepositoryInterface
{
    public function __construct(
        private readonly ProductSpecificationValueMapResource $resource,
        private readonly ProductSpecificationValueMapFactory $mapFactory,
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    public function getBySourceRowId(int $eccubeProductSpecificationClassId): ?ProductSpecificationValueMap
    {
        /** @var ProductSpecificationValueMap $map */
        $map = $this->mapFactory->create();
        $this->resource->load($map, $eccubeProductSpecificationClassId, 'eccube_product_specification_class_id');

        return $map->getId() === null ? null : $map;
    }

    public function save(ProductSpecificationValueMap $map): ProductSpecificationValueMap
    {
        try {
            $this->resource->save($map);
        } catch (AlreadyExistsException $e) {
            throw new CouldNotSaveException(
                __(
                    'Could not save product specification value map for source row %1: %2',
                    $map->getEccubeProductSpecificationClassId(),
                    $e->getMessage()
                ),
                $e
            );
        }

        return $map;
    }

    public function getByProductAndSpecification(int $eccubeProductId, int $eccubeSpecificationId): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('eccube_product_id', $eccubeProductId);
        $collection->addFieldToFilter('eccube_specification_id', $eccubeSpecificationId);
        $collection->setOrder('position', 'ASC');

        return array_values($collection->getItems());
    }
}
