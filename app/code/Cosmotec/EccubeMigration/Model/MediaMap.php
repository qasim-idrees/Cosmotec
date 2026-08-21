<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model;

use Cosmotec\EccubeMigration\Model\ResourceModel\MediaMap as MediaMapResource;
use Magento\Framework\Model\AbstractModel;

/**
 * Canonical source identity is (relation_type, eccube_owner_id,
 * eccube_upload_file_id). The legacy eccube_image_map / eccube_image_id
 * identity is no longer used by the production pipeline.
 *
 * @method int getEccubeUploadFileId()
 * @method $this setEccubeUploadFileId(int $id)
 * @method string getRelationType()
 * @method $this setRelationType(string $type)
 * @method int getEccubeOwnerId()
 * @method $this setEccubeOwnerId(int $id)
 * @method int getSortNo()
 * @method $this setSortNo(int $sortNo)
 * @method string getSourceFileName()
 * @method $this setSourceFileName(string $name)
 * @method $this setFileExtension(?string $ext)
 * @method $this setMediaClass(string $class)
 * @method $this setMagentoEntityType(?string $type)
 * @method int|null getMagentoEntityId()
 * @method $this setMagentoEntityId(?int $id)
 * @method string|null getMagentoFilePath()
 * @method $this setMagentoFilePath(?string $path)
 * @method string|null getMagentoRole()
 * @method $this setMagentoRole(?string $role)
 * @method string|null getContentHash()
 * @method $this setContentHash(?string $hash)
 * @method string getStatus()
 * @method $this setStatus(string $status)
 * @method $this setErrorMessage(?string $message)
 * @method $this setLastSyncedAt(?string $timestamp)
 */
class MediaMap extends AbstractModel
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_IMPORTED = 'imported';
    public const STATUS_UPDATED = 'updated';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_ERROR = 'error';
    public const STATUS_NEEDS_REVIEW = 'needs_review';
    public const STATUS_OBSOLETE = 'obsolete';

    protected function _construct(): void
    {
        $this->_init(MediaMapResource::class);
    }
}
