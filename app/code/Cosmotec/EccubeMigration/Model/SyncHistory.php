<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model;

use Cosmotec\EccubeMigration\Model\ResourceModel\SyncHistory as SyncHistoryResource;
use Magento\Framework\Model\AbstractModel;

/**
 * @method string getRunId()
 * @method $this setRunId(string $runId)
 * @method string getEntityType()
 * @method $this setEntityType(string $entityType)
 * @method string getOperation()
 * @method $this setOperation(string $operation)
 * @method int getSourceId()
 * @method $this setSourceId(int $sourceId)
 * @method int|null getTargetId()
 * @method $this setTargetId(?int $targetId)
 * @method string getStatus()
 * @method $this setStatus(string $status)
 * @method string|null getMessage()
 * @method $this setMessage(?string $message)
 * @method int|null getDurationMs()
 * @method $this setDurationMs(?int $durationMs)
 * @method int|null getMemoryBytes()
 * @method $this setMemoryBytes(?int $memoryBytes)
 */
class SyncHistory extends AbstractModel
{
    public const ENTITY_TYPE_CATEGORY = 'category';
    public const ENTITY_TYPE_ITEM = 'item';
    public const ENTITY_TYPE_PRODUCT = 'product';
    public const ENTITY_TYPE_PRODUCT_CLASS = 'product_class';
    public const ENTITY_TYPE_IMAGE = 'image';
    public const ENTITY_TYPE_INVENTORY = 'inventory';
    public const ENTITY_TYPE_PRODUCT_REFERENCE = 'product_reference';
    public const ENTITY_TYPE_ATTRIBUTE = 'attribute';
    public const ENTITY_TYPE_ATTRIBUTE_OPTION = 'attribute_option';

    public const OPERATION_IMPORT = 'import';
    public const OPERATION_SYNC = 'sync';

    public const STATUS_IMPORTED = 'imported';
    public const STATUS_UPDATED = 'updated';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_ERROR = 'error';

    protected function _construct(): void
    {
        $this->_init(SyncHistoryResource::class);
    }
}
