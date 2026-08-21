<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model;

use Cosmotec\EccubeMigration\Model\ResourceModel\SpecificationMap as SpecificationMapResource;
use Magento\Framework\Model\AbstractModel;

/**
 * @method int getEccubeSpecificationId()
 * @method $this setEccubeSpecificationId(int $id)
 * @method string getAttributeCode()
 * @method $this setAttributeCode(string $code)
 * @method int|null getMagentoAttributeId()
 * @method $this setMagentoAttributeId(?int $id)
 * @method $this setLabelEn(?string $label)
 * @method $this setLabelJa(?string $label)
 * @method $this setUsedAtItemScope(int $flag)
 * @method $this setUsedAtProductScope(int $flag)
 * @method $this setSelectableCount(int $count)
 * @method $this setIsFilterable(int $flag)
 * @method $this setOptionCount(int $count)
 * @method $this setSortNo(int $sortNo)
 * @method string getClassification()
 * @method $this setClassification(string $classification)
 * @method $this setClassificationReason(?string $reason)
 * @method string getStatus()
 * @method $this setStatus(string $status)
 * @method $this setErrorMessage(?string $message)
 * @method $this setLastSyncedAt(?string $timestamp)
 */
class SpecificationMap extends AbstractModel
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_IMPORTED = 'imported';
    public const STATUS_UPDATED = 'updated';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_ERROR = 'error';

    protected function _construct(): void
    {
        $this->_init(SpecificationMapResource::class);
    }
}
