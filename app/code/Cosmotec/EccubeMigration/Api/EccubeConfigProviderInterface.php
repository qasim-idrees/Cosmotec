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

    /**
     * Whether the media pipeline may fall back to EC-CUBE's remote S3/
     * CloudFront storage when a source file is absent from the local
     * EC-CUBE Image Folder Path. Off by default: existing local-only
     * behavior is unchanged unless explicitly enabled.
     */
    public function isRemoteMediaFallbackEnabled(): bool;

    /**
     * Base URL of the EC-CUBE remote media CDN (e.g.
     * https://static.cosmotec-co.jp), never hardcoded in code. Null when
     * unconfigured, in which case remote fallback cannot run regardless
     * of isRemoteMediaFallbackEnabled().
     */
    public function getRemoteMediaBaseUrl(): ?string;

    /**
     * Connect/read timeout, in seconds, for remote media existence checks
     * and downloads.
     */
    public function getRemoteMediaTimeout(): int;
}
