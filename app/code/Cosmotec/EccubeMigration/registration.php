<?php
/**
 * Cosmotec_EccubeMigration
 *
 * EC-CUBE 4.0.0 to Magento 2.4.8-p3 migration and synchronization framework.
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

use Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(
    ComponentRegistrar::MODULE,
    'Cosmotec_EccubeMigration',
    __DIR__
);
