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
 * Distinct logger channel writing to var/log/eccube_import.log. The name
 * and handler stack are configured in etc/di.xml so any class can simply
 * type-hint ImportLogger and get the correctly-routed logger via DI.
 */
class ImportLogger extends Monolog
{
}
