<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Repository;

use Cosmotec\EccubeMigration\Api\MediaMapRepositoryInterface;
use Cosmotec\EccubeMigration\Model\Media\MediaRelationType;
use Cosmotec\EccubeMigration\Model\MediaMap;
use Cosmotec\EccubeMigration\Model\MediaMapFactory;
use Cosmotec\EccubeMigration\Model\ResourceModel\MediaMap as MediaMapResource;
use Cosmotec\EccubeMigration\Model\ResourceModel\MediaMap\CollectionFactory;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\CouldNotSaveException;

class MediaMapRepository implements MediaMapRepositoryInterface
{
    public function __construct(
        private readonly MediaMapResource $resource,
        private readonly MediaMapFactory $mapFactory,
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    public function get(MediaRelationType $relationType, int $ownerId, int $uploadFileId): ?MediaMap
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('relation_type', $relationType->value);
        $collection->addFieldToFilter('eccube_owner_id', $ownerId);
        $collection->addFieldToFilter('eccube_upload_file_id', $uploadFileId);
        $collection->setPageSize(1);

        $items = array_values($collection->getItems());

        return $items[0] ?? null;
    }

    public function save(MediaMap $map): MediaMap
    {
        try {
            $this->resource->save($map);
        } catch (AlreadyExistsException $e) {
            throw new CouldNotSaveException(
                __(
                    'Could not save media map for %1 owner %2 file %3: %4',
                    $map->getRelationType(),
                    $map->getEccubeOwnerId(),
                    $map->getEccubeUploadFileId(),
                    $e->getMessage()
                ),
                $e
            );
        }

        return $map;
    }

    public function getByOwner(MediaRelationType $relationType, int $ownerId): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('relation_type', $relationType->value);
        $collection->addFieldToFilter('eccube_owner_id', $ownerId);
        $collection->setOrder('sort_no', 'ASC');

        return array_values($collection->getItems());
    }

    public function getOtherActiveUsages(int $uploadFileId, int $excludeEntityId): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('eccube_upload_file_id', $uploadFileId);
        $collection->addFieldToFilter('entity_id', ['neq' => $excludeEntityId]);
        $collection->addFieldToFilter('status', [
            'in' => [MediaMap::STATUS_IMPORTED, MediaMap::STATUS_UPDATED],
        ]);

        return array_values($collection->getItems());
    }

    public function getFailed(MediaRelationType $relationType, int $limit): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('relation_type', $relationType->value);
        $collection->addFieldToFilter('status', [
            'in' => [MediaMap::STATUS_ERROR, MediaMap::STATUS_PENDING, MediaMap::STATUS_NEEDS_REVIEW],
        ]);
        $collection->setPageSize($limit);
        $collection->setCurPage(1);

        return array_values($collection->getItems());
    }

    public function countByStatus(MediaRelationType $relationType, string $status): int
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('relation_type', $relationType->value);
        $collection->addFieldToFilter('status', $status);

        return $collection->getSize();
    }
}
