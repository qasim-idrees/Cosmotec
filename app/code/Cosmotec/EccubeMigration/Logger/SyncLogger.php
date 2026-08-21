<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Logger;

use Magento\Framework\Logger\Monolog;

/**
 * Distinct logger channel writing to var/log/eccube_sync.log.
 */
class SyncLogger extends Monolog
{
}
