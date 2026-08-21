<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model;

use Cosmotec\EccubeMigration\Model\ResourceModel\ProductSpecificationValueMap as ProductSpecificationValueMapResource;
use Magento\Framework\Model\AbstractModel;

/**
 * PENDING DESIGN - see MultiValueSpecificationRegistry and BUILD_STATUS.md.
 * Backs eccube_product_specification_value, the lossless positional store
 * for the 5 confirmed multi-value specifications.
 *
 * @method int getEccubeProductSpecificationClassId()
 * @method $this setEccubeProductSpecificationClassId(int $id)
 * @method int getEccubeProductId()
 * @method $this setEccubeProductId(int $id)
 * @method int getEccubeSpecificationId()
 * @method $this setEccubeSpecificationId(int $id)
 * @method int getEccubeSpecificationClassId()
 * @method $this setEccubeSpecificationClassId(int $id)
 * @method int|null getMagentoOptionId()
 * @method $this setMagentoOptionId(?int $id)
 * @method int getPosition()
 * @method $this setPosition(int $position)
 * @method int|null getMagentoProductId()
 * @method $this setMagentoProductId(?int $id)
 * @method string getStatus()
 * @method $this setStatus(string $status)
 * @method $this setErrorMessage(?string $message)
 * @method $this setLastSyncedAt(?string $timestamp)
 */
class ProductSpecificationValueMap extends AbstractModel
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_IMPORTED = 'imported';
    public const STATUS_UPDATED = 'updated';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_ERROR = 'error';

    protected function _construct(): void
    {
        $this->_init(ProductSpecificationValueMapResource::class);
    }
}
