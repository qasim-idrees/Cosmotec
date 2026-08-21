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

class ImportHandler extends Base
{
    protected $fileName = '/var/log/eccube_import.log';

    protected $loggerType = MonologLogger::INFO;
}
