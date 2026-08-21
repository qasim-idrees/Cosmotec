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

class ErrorHandler extends Base
{
    protected $fileName = '/var/log/eccube_error.log';

    protected $loggerType = MonologLogger::ERROR;
}
