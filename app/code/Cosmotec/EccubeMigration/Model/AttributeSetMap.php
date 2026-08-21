<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model;

use Cosmotec\EccubeMigration\Model\ResourceModel\AttributeSetMap as AttributeSetMapResource;
use Magento\Framework\Model\AbstractModel;

/**
 * @method int getEccubeTopLevelCategoryId()
 * @method $this setEccubeTopLevelCategoryId(int $id)
 * @method string getSetName()
 * @method $this setSetName(string $name)
 * @method int|null getMagentoAttributeSetId()
 * @method $this setMagentoAttributeSetId(?int $id)
 * @method int getSpecificationCount()
 * @method $this setSpecificationCount(int $count)
 * @method string|null getContentHash()
 * @method $this setContentHash(?string $hash)
 * @method string getStatus()
 * @method $this setStatus(string $status)
 * @method $this setErrorMessage(?string $message)
 * @method $this setLastSyncedAt(?string $timestamp)
 */
class AttributeSetMap extends AbstractModel
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_IMPORTED = 'imported';
    public const STATUS_UPDATED = 'updated';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_ERROR = 'error';

    protected function _construct(): void
    {
        $this->_init(AttributeSetMapResource::class);
    }
}
