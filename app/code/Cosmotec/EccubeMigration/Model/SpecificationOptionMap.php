<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model;

use Cosmotec\EccubeMigration\Model\ResourceModel\SpecificationOptionMap as SpecificationOptionMapResource;
use Magento\Framework\Model\AbstractModel;

/**
 * @method int getEccubeSpecificationClassId()
 * @method $this setEccubeSpecificationClassId(int $id)
 * @method int getEccubeSpecificationId()
 * @method $this setEccubeSpecificationId(int $id)
 * @method string getAttributeCode()
 * @method $this setAttributeCode(string $code)
 * @method int|null getMagentoOptionId()
 * @method $this setMagentoOptionId(?int $id)
 * @method string|null getLabelEn()
 * @method $this setLabelEn(?string $label)
 * @method $this setLabelJa(?string $label)
 * @method int getSortNo()
 * @method $this setSortNo(int $sortNo)
 * @method string getStatus()
 * @method $this setStatus(string $status)
 * @method $this setErrorMessage(?string $message)
 * @method $this setLastSyncedAt(?string $timestamp)
 */
class SpecificationOptionMap extends AbstractModel
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_IMPORTED = 'imported';
    public const STATUS_UPDATED = 'updated';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_ERROR = 'error';

    protected function _construct(): void
    {
        $this->_init(SpecificationOptionMapResource::class);
    }
}
