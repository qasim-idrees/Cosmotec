<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model;

use Cosmotec\EccubeMigration\Model\ResourceModel\RelatedProductMap as RelatedProductMapResource;
use Magento\Framework\Model\AbstractModel;

/**
 * @method int getEccubeRelatedId()
 * @method $this setEccubeRelatedId(int $id)
 * @method int getEccubeProductId()
 * @method $this setEccubeProductId(int $id)
 * @method int getEccubeRelatedProductId()
 * @method $this setEccubeRelatedProductId(int $id)
 * @method int|null getMagentoProductId()
 * @method $this setMagentoProductId(?int $id)
 * @method int|null getMagentoRelatedProductId()
 * @method $this setMagentoRelatedProductId(?int $id)
 * @method int getSortNo()
 * @method $this setSortNo(int $sortNo)
 * @method string getStatus()
 * @method $this setStatus(string $status)
 * @method $this setErrorMessage(?string $message)
 * @method $this setLastSyncedAt(?string $timestamp)
 */
class RelatedProductMap extends AbstractModel
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_IMPORTED = 'imported';
    public const STATUS_UPDATED = 'updated';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_ERROR = 'error';
    public const STATUS_OBSOLETE = 'obsolete';

    protected function _construct(): void
    {
        $this->_init(RelatedProductMapResource::class);
    }
}
