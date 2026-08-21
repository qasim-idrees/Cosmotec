<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Api;

/**
 * Typed accessor for all Stores > Configuration > Cosmotec > EC-CUBE Migration
 * settings. Implemented by \Cosmotec\EccubeMigration\Model\Config\ModuleConfig.
 */
interface EccubeConfigProviderInterface
{
    public function isEnabled(): bool;

    public function isDryRunByDefault(): bool;

    public function isLoggingEnabled(): bool;

    public function getBatchSize(): int;

    public function getImageFolder(): ?string;

    public function getMagentoRootCategoryId(): int;

    public function isScheduledImportEnabled(): bool;

    public function isScheduledSyncEnabled(): bool;

    public function getHost(): string;

    public function getPort(): int;

    public function getDatabase(): string;

    public function getUsername(): string;

    public function getPassword(): string;

    public function getCharset(): string;

    public function getTimeout(): int;
}
