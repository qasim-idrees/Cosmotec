<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Repository;

use Cosmotec\EccubeMigration\Api\ImageMapRepositoryInterface;
use Cosmotec\EccubeMigration\Model\ImageMap;
use Cosmotec\EccubeMigration\Model\ImageMapFactory;
use Cosmotec\EccubeMigration\Model\ResourceModel\ImageMap as ImageMapResource;
use Cosmotec\EccubeMigration\Model\ResourceModel\ImageMap\CollectionFactory as ImageMapCollectionFactory;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\CouldNotSaveException;

class ImageMapRepository implements ImageMapRepositoryInterface
{
    public function __construct(
        private readonly ImageMapResource $resource,
        private readonly ImageMapFactory $imageMapFactory,
        private readonly ImageMapCollectionFactory $collectionFactory
    ) {
    }

    public function getByEccubeImageId(int $eccubeImageId): ?ImageMap
    {
        /** @var ImageMap $map */
        $map = $this->imageMapFactory->create();
        $this->resource->load($map, $eccubeImageId, 'eccube_image_id');

        return $map->getId() === null ? null : $map;
    }

    public function save(ImageMap $imageMap): ImageMap
    {
        try {
            $this->resource->save($imageMap);
        } catch (AlreadyExistsException $e) {
            throw new CouldNotSaveException(
                __('Could not save image map for EC-CUBE image %1: %2', $imageMap->getEccubeImageId(), $e->getMessage()),
                $e
            );
        }

        return $imageMap;
    }

    public function getUnfinished(int $limit): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('status', ['in' => [ImageMap::STATUS_PENDING, ImageMap::STATUS_ERROR]]);
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

    public function hasMainImage(int $eccubeProductId): bool
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('eccube_product_id', $eccubeProductId);
        $collection->addFieldToFilter('is_main', 1);
        $collection->addFieldToFilter('status', ['in' => [ImageMap::STATUS_IMPORTED, ImageMap::STATUS_UPDATED]]);

        return $collection->getSize() > 0;
    }
}
