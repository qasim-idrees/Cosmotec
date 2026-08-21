<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model;

use Cosmotec\EccubeMigration\Model\ResourceModel\ProductReferenceMap as ProductReferenceMapResource;
use Magento\Framework\Model\AbstractModel;

/**
 * @method int getEccubeReferenceId()
 * @method $this setEccubeReferenceId(int $id)
 * @method int getEccubeProductId()
 * @method $this setEccubeProductId(int $id)
 * @method int|null getMagentoProductId()
 * @method $this setMagentoProductId(?int $id)
 * @method string|null getReferenceName()
 * @method $this setReferenceName(?string $name)
 * @method string|null getReferenceLink()
 * @method $this setReferenceLink(?string $link)
 * @method int getSortNo()
 * @method $this setSortNo(int $sortNo)
 * @method string|null getContentHash()
 * @method $this setContentHash(?string $hash)
 * @method string getStatus()
 * @method $this setStatus(string $status)
 * @method $this setErrorMessage(?string $message)
 * @method $this setLastSyncedAt(?string $timestamp)
 */
class ProductReferenceMap extends AbstractModel
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_IMPORTED = 'imported';
    public const STATUS_UPDATED = 'updated';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_ERROR = 'error';
    public const STATUS_OBSOLETE = 'obsolete';

    protected function _construct(): void
    {
        $this->_init(ProductReferenceMapResource::class);
    }
}
