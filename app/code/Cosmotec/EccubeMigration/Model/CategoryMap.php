<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model;

use Cosmotec\EccubeMigration\Model\ResourceModel\CategoryMap as CategoryMapResource;
use Magento\Framework\Model\AbstractModel;

/**
 * @method int getEccubeCategoryId()
 * @method $this setEccubeCategoryId(int $eccubeCategoryId)
 * @method int|null getMagentoCategoryId()
 * @method $this setMagentoCategoryId(?int $magentoCategoryId)
 * @method string|null getContentHash()
 * @method $this setContentHash(?string $hash)
 * @method string getStatus()
 * @method $this setStatus(string $status)
 * @method string|null getErrorMessage()
 * @method $this setErrorMessage(?string $message)
 * @method string|null getLastSyncedAt()
 * @method $this setLastSyncedAt(?string $timestamp)
 */
class CategoryMap extends AbstractModel
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_IMPORTED = 'imported';
    public const STATUS_UPDATED = 'updated';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_ERROR = 'error';
    public const STATUS_OBSOLETE = 'obsolete';

    protected function _construct(): void
    {
        $this->_init(CategoryMapResource::class);
    }
}
