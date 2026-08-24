<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model;

use Cosmotec\EccubeMigration\Model\ResourceModel\ItemAdditionalContentMap as ItemAdditionalContentMapResource;
use Magento\Framework\Model\AbstractModel;

/**
 * @method int getEccubeAdditionalInformationId()
 * @method $this setEccubeAdditionalInformationId(int $id)
 * @method int getEccubeItemId()
 * @method $this setEccubeItemId(int $id)
 * @method int|null getMagentoProductId()
 * @method $this setMagentoProductId(?int $id)
 * @method string|null getTabNameEn()
 * @method $this setTabNameEn(?string $name)
 * @method string|null getTabNameJa()
 * @method $this setTabNameJa(?string $name)
 * @method string|null getHtmlContent()
 * @method $this setHtmlContent(?string $html)
 * @method int getSortNo()
 * @method $this setSortNo(int $sortNo)
 * @method string|null getContentHash()
 * @method $this setContentHash(?string $hash)
 * @method string getStatus()
 * @method $this setStatus(string $status)
 * @method $this setErrorMessage(?string $message)
 * @method $this setLastSyncedAt(?string $timestamp)
 */
class ItemAdditionalContentMap extends AbstractModel
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_IMPORTED = 'imported';
    public const STATUS_UPDATED = 'updated';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_ERROR = 'error';
    public const STATUS_OBSOLETE = 'obsolete';

    protected function _construct(): void
    {
        $this->_init(ItemAdditionalContentMapResource::class);
    }
}
