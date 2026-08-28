<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Config;

use Cosmotec\EccubeMigration\Api\EccubeConfigProviderInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;

/**
 * Reads Stores > Configuration > Cosmotec > EC-CUBE Migration settings and
 * exposes them as typed values. This is the single source of truth for
 * configuration in the module; every other class depends on
 * EccubeConfigProviderInterface rather than reading ScopeConfig directly.
 */
class ModuleConfig implements EccubeConfigProviderInterface
{
    private const XML_PATH_ENABLED = 'cosmotec_eccube_migration/general/enabled';
    private const XML_PATH_DRY_RUN = 'cosmotec_eccube_migration/general/dry_run';
    private const XML_PATH_LOGGING_ENABLED = 'cosmotec_eccube_migration/general/logging_enabled';
    private const XML_PATH_BATCH_SIZE = 'cosmotec_eccube_migration/general/batch_size';
    private const XML_PATH_IMAGE_FOLDER = 'cosmotec_eccube_migration/general/image_folder';
    private const XML_PATH_MAGENTO_ROOT_CATEGORY_ID = 'cosmotec_eccube_migration/general/magento_root_category_id';
    private const XML_PATH_ENABLE_SCHEDULED_IMPORT = 'cosmotec_eccube_migration/cron/enable_scheduled_import';
    private const XML_PATH_ENABLE_SCHEDULED_SYNC = 'cosmotec_eccube_migration/cron/enable_scheduled_sync';

    private const XML_PATH_REMOTE_MEDIA_FALLBACK_ENABLED = 'cosmotec_eccube_migration/remote_media/enabled';
    private const XML_PATH_REMOTE_MEDIA_BASE_URL = 'cosmotec_eccube_migration/remote_media/base_url';
    private const XML_PATH_REMOTE_MEDIA_TIMEOUT = 'cosmotec_eccube_migration/remote_media/timeout';

    private const XML_PATH_HOST = 'cosmotec_eccube_migration/connection/host';
    private const XML_PATH_PORT = 'cosmotec_eccube_migration/connection/port';
    private const XML_PATH_DATABASE = 'cosmotec_eccube_migration/connection/database';
    private const XML_PATH_USERNAME = 'cosmotec_eccube_migration/connection/username';
    private const XML_PATH_PASSWORD = 'cosmotec_eccube_migration/connection/password';
    private const XML_PATH_CHARSET = 'cosmotec_eccube_migration/connection/charset';
    private const XML_PATH_TIMEOUT = 'cosmotec_eccube_migration/connection/timeout';

    private const DEFAULT_BATCH_SIZE = 100;
    private const DEFAULT_CHARSET = 'utf8mb4';
    private const DEFAULT_PORT = 3306;
    private const DEFAULT_TIMEOUT = 5;
    private const DEFAULT_ROOT_CATEGORY_ID = 2;
    private const DEFAULT_REMOTE_MEDIA_TIMEOUT = 10;

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor,
        private readonly string $scopeType = ScopeConfigInterface::SCOPE_TYPE_DEFAULT
    ) {
    }

    public function isEnabled(): bool
    {
        return (bool) $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, $this->scopeType);
    }

    public function isDryRunByDefault(): bool
    {
        return (bool) $this->scopeConfig->isSetFlag(self::XML_PATH_DRY_RUN, $this->scopeType);
    }

    public function isLoggingEnabled(): bool
    {
        return (bool) $this->scopeConfig->isSetFlag(self::XML_PATH_LOGGING_ENABLED, $this->scopeType);
    }

    public function getBatchSize(): int
    {
        $value = (int) $this->scopeConfig->getValue(self::XML_PATH_BATCH_SIZE, $this->scopeType);

        return $value > 0 ? $value : self::DEFAULT_BATCH_SIZE;
    }

    public function getImageFolder(): ?string
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_IMAGE_FOLDER, $this->scopeType);

        return $value !== null && $value !== '' ? (string) $value : null;
    }

    public function getMagentoRootCategoryId(): int
    {
        $value = (int) $this->scopeConfig->getValue(self::XML_PATH_MAGENTO_ROOT_CATEGORY_ID, $this->scopeType);

        return $value > 0 ? $value : self::DEFAULT_ROOT_CATEGORY_ID;
    }

    public function isScheduledImportEnabled(): bool
    {
        return (bool) $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLE_SCHEDULED_IMPORT, $this->scopeType);
    }

    public function isScheduledSyncEnabled(): bool
    {
        return (bool) $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLE_SCHEDULED_SYNC, $this->scopeType);
    }

    public function getHost(): string
    {
        return (string) $this->scopeConfig->getValue(self::XML_PATH_HOST, $this->scopeType);
    }

    public function getPort(): int
    {
        $value = (int) $this->scopeConfig->getValue(self::XML_PATH_PORT, $this->scopeType);

        return $value > 0 ? $value : self::DEFAULT_PORT;
    }

    public function getDatabase(): string
    {
        return (string) $this->scopeConfig->getValue(self::XML_PATH_DATABASE, $this->scopeType);
    }

    public function getUsername(): string
    {
        return (string) $this->scopeConfig->getValue(self::XML_PATH_USERNAME, $this->scopeType);
    }

    public function getPassword(): string
    {
        $encrypted = (string) $this->scopeConfig->getValue(self::XML_PATH_PASSWORD, $this->scopeType);

        return $encrypted === '' ? '' : $this->encryptor->decrypt($encrypted);
    }

    public function getCharset(): string
    {
        $value = (string) $this->scopeConfig->getValue(self::XML_PATH_CHARSET, $this->scopeType);

        return $value !== '' ? $value : self::DEFAULT_CHARSET;
    }

    public function getTimeout(): int
    {
        $value = (int) $this->scopeConfig->getValue(self::XML_PATH_TIMEOUT, $this->scopeType);

        return $value > 0 ? $value : self::DEFAULT_TIMEOUT;
    }

    public function isRemoteMediaFallbackEnabled(): bool
    {
        return (bool) $this->scopeConfig->isSetFlag(self::XML_PATH_REMOTE_MEDIA_FALLBACK_ENABLED, $this->scopeType);
    }

    public function getRemoteMediaBaseUrl(): ?string
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_REMOTE_MEDIA_BASE_URL, $this->scopeType);
        $value = $value !== null ? rtrim((string) $value, '/') : '';

        return $value !== '' ? $value : null;
    }

    public function getRemoteMediaTimeout(): int
    {
        $value = (int) $this->scopeConfig->getValue(self::XML_PATH_REMOTE_MEDIA_TIMEOUT, $this->scopeType);

        return $value > 0 ? $value : self::DEFAULT_REMOTE_MEDIA_TIMEOUT;
    }
}
