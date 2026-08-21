<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Logger\Handler;

use Magento\Framework\Logger\Handler\Base;
use Monolog\Logger as MonologLogger;

class SyncHandler extends Base
{
    protected $fileName = '/var/log/eccube_sync.log';

    protected $loggerType = MonologLogger::INFO;
}
