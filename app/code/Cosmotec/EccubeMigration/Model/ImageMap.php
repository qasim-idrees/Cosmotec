<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model;

use Cosmotec\EccubeMigration\Model\ResourceModel\ImageMap as ImageMapResource;
use Magento\Framework\Model\AbstractModel;

/**
 * @method int getEccubeImageId()
 * @method $this setEccubeImageId(int $eccubeImageId)
 * @method int getEccubeProductId()
 * @method $this setEccubeProductId(int $eccubeProductId)
 * @method int|null getMagentoProductId()
 * @method $this setMagentoProductId(?int $magentoProductId)
 * @method int|null getGalleryValueId()
 * @method $this setGalleryValueId(?int $galleryValueId)
 * @method string|null getFileName()
 * @method $this setFileName(?string $fileName)
 * @method string|null getContentHash()
 * @method $this setContentHash(?string $hash)
 * @method int getIsMain()
 * @method $this setIsMain(int $isMain)
 * @method string getStatus()
 * @method $this setStatus(string $status)
 * @method string|null getErrorMessage()
 * @method $this setErrorMessage(?string $message)
 * @method string|null getLastSyncedAt()
 * @method $this setLastSyncedAt(?string $timestamp)
 */
class ImageMap extends AbstractModel
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_IMPORTED = 'imported';
    public const STATUS_UPDATED = 'updated';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_ERROR = 'error';

    protected function _construct(): void
    {
        $this->_init(ImageMapResource::class);
    }
}
