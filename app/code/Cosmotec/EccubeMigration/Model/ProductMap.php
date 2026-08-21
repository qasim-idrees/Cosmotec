<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model;

use Cosmotec\EccubeMigration\Model\ResourceModel\ProductMap as ProductMapResource;
use Magento\Framework\Model\AbstractModel;

/**
 * @method int getEccubeProductId()
 * @method $this setEccubeProductId(int $eccubeProductId)
 * @method int|null getEccubeItemId()
 * @method $this setEccubeItemId(?int $eccubeItemId)
 * @method int|null getMagentoProductId()
 * @method $this setMagentoProductId(?int $magentoProductId)
 * @method string|null getSku()
 * @method $this setSku(?string $sku)
 * @method string|null getContentHash()
 * @method $this setContentHash(?string $hash)
 * @method string getStatus()
 * @method $this setStatus(string $status)
 * @method int getRelationLinked()
 * @method $this setRelationLinked(int $relationLinked)
 * @method string|null getInventoryContentHash()
 * @method $this setInventoryContentHash(?string $hash)
 * @method string|null getInventorySyncedAt()
 * @method $this setInventorySyncedAt(?string $timestamp)
 * @method string|null getSpecificationValueHash()
 * @method $this setSpecificationValueHash(?string $hash)
 * @method string|null getSpecificationValuesSyncedAt()
 * @method $this setSpecificationValuesSyncedAt(?string $timestamp)
 * @method string|null getErrorMessage()
 * @method $this setErrorMessage(?string $message)
 * @method string|null getLastSyncedAt()
 * @method $this setLastSyncedAt(?string $timestamp)
 */
class ProductMap extends AbstractModel
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_IMPORTED = 'imported';
    public const STATUS_UPDATED = 'updated';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_ERROR = 'error';

    protected function _construct(): void
    {
        $this->_init(ProductMapResource::class);
    }
}
