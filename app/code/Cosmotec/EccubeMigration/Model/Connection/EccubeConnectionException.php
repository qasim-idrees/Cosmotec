<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Connection;

use Magento\Framework\Exception\LocalizedException;

/**
 * Thrown when the dedicated EC-CUBE PDO connection cannot be established
 * or a query against it fails.
 */
class EccubeConnectionException extends LocalizedException
{
}
