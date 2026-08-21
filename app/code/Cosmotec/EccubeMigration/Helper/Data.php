<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Helper;

use Cosmotec\EccubeMigration\Api\EccubeConfigProviderInterface;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;

/**
 * Convenience Helper for adminhtml blocks/templates that expect the
 * conventional Magento Helper access pattern. All business logic and CLI
 * commands should depend on EccubeConfigProviderInterface directly rather
 * than this class.
 */
class Data extends AbstractHelper
{
    public function __construct(
        Context $context,
        private readonly EccubeConfigProviderInterface $config
    ) {
        parent::__construct($context);
    }

    public function isEnabled(): bool
    {
        return $this->config->isEnabled();
    }

    public function isDryRunByDefault(): bool
    {
        return $this->config->isDryRunByDefault();
    }

    public function isLoggingEnabled(): bool
    {
        return $this->config->isLoggingEnabled();
    }

    public function getBatchSize(): int
    {
        return $this->config->getBatchSize();
    }

    public function getImageFolder(): ?string
    {
        return $this->config->getImageFolder();
    }
}
