<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Media;

use Cosmotec\EccubeMigration\Api\Data\MediaFileInterface;
use Cosmotec\EccubeMigration\Api\EccubeConfigProviderInterface;
use Cosmotec\EccubeMigration\Logger\ImportLogger;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;

/**
 * Implements the resolution order required for media source lookup:
 *
 *   1. Local filesystem (EC-CUBE Image Folder Path) - completely
 *      unchanged from the pre-existing behavior, always tried first.
 *   2. Remote EC-CUBE S3/CloudFront storage - only consulted when local
 *      fails AND isRemoteMediaFallbackEnabled() AND a base URL is
 *      configured. Off by default, so an installation that never
 *      configures this setting behaves byte-for-byte as before.
 *   3. Genuine not-found - neither source has it (or remote couldn't be
 *      reached at all - see SOURCE_FILE_NOT_FOUND vs REMOTE_FETCH_FAILED
 *      in the returned message).
 *
 * A remote hit is downloaded once into a temporary file (var/tmp/
 * cosmotec_eccube_remote_media/) and handed back as the resolved
 * absolute path - the caller (MediaImporter) feeds that path through its
 * existing, completely unmodified read/copy logic exactly as it would a
 * local path, then calls cleanup() when done. Nothing is ever
 * permanently copied out of the remote store by this class - only the
 * one file currently being imported exists on local disk at a time, and
 * only for the duration of that single import.
 */
class RemoteMediaResolver
{
    private const TEMP_SUBDIR = 'cosmotec_eccube_remote_media';

    public function __construct(
        private readonly EccubeConfigProviderInterface $config,
        private readonly RemoteMediaLocator $locator,
        private readonly RemoteMediaClient $client,
        private readonly Filesystem $filesystem,
        private readonly ImportLogger $logger
    ) {
    }

    public function resolve(MediaFileInterface $file): MediaSourceResolution
    {
        $localFolder = $this->config->getImageFolder();

        if ($localFolder !== null) {
            $localPath = $file->getAbsolutePath($localFolder);

            if (is_file($localPath)) {
                return MediaSourceResolution::local($localPath);
            }
        }

        if (!$this->config->isRemoteMediaFallbackEnabled()) {
            return MediaSourceResolution::notFound($this->buildSourceFileNotFoundMessage(
                $file,
                $localFolder,
                'remote media fallback is disabled'
            ));
        }

        $baseUrl = $this->config->getRemoteMediaBaseUrl();

        if ($baseUrl === null) {
            return MediaSourceResolution::notFound($this->buildSourceFileNotFoundMessage(
                $file,
                $localFolder,
                'remote media fallback is enabled but no base URL is configured'
            ));
        }

        return $this->resolveRemote($file, $localFolder, $baseUrl);
    }

    /**
     * Deletes the temporary download created for a REMOTE resolution.
     * A no-op for LOCAL/NOT_FOUND results. Always safe to call, including
     * from a finally block after a failed import.
     */
    public function cleanup(MediaSourceResolution $resolution): void
    {
        $path = $resolution->getAbsolutePath();

        if ($resolution->isTemporary() && $path !== null && is_file($path)) {
            if (!@unlink($path)) {
                $this->logger->error(sprintf('RemoteMediaResolver: could not delete temp file "%s"', $path));
            }
        }
    }

    /**
     * GET-only: a single request both confirms existence and, on
     * success, delivers the file - no separate HEAD/existence check
     * beforehand. See RemoteMediaClient's class docblock for why (real
     * per-file timing showed the old HEAD-then-GET pattern cost a full
     * extra request-and-TLS-handshake on the success path, which is
     * 99.85% of cases; HEAD was never once ambiguous on this CDN across
     * 100+ real checks). The 403/404 vs timeout/5xx/write-failure
     * distinction (REMOTE_NOT_FOUND vs REMOTE_FETCH_FAILED) is
     * unchanged - RemoteCheckResult still carries it, just now decided
     * by the one GET's own status instead of a preceding HEAD's.
     */
    private function resolveRemote(MediaFileInterface $file, ?string $localFolder, string $baseUrl): MediaSourceResolution
    {
        $timeout = $this->config->getRemoteMediaTimeout();
        $url = $this->locator->buildOriginalUrl($baseUrl, $file->getRelationType(), $file->getFileName());
        $tempPath = $this->buildTempPath($file);
        $result = $this->client->fetch($url, $tempPath, $timeout);

        if ($result->isTransient()) {
            return MediaSourceResolution::notFound(sprintf(
                'REMOTE_FETCH_FAILED: relation=%s upload_file_id=%d owner_id=%d file_name="%s" '
                . 'remote_url="%s" reason="%s" - existence could not be confirmed, not a confirmed absence',
                $file->getRelationType()->value,
                $file->getUploadFileId(),
                $file->getOwnerId(),
                $file->getFileName(),
                $url,
                $result->getMessage()
            ));
        }

        if (!$result->isFound()) {
            return MediaSourceResolution::notFound($this->buildSourceFileNotFoundMessage(
                $file,
                $localFolder,
                sprintf('also absent from remote storage (%s, url="%s")', $result->getMessage(), $url)
            ));
        }

        $this->logger->info(sprintf(
            'RemoteMediaResolver: recovered %s/%d (owner %d) from remote storage: %s',
            $file->getRelationType()->value,
            $file->getUploadFileId(),
            $file->getOwnerId(),
            $url
        ));

        return MediaSourceResolution::remote($tempPath);
    }

    private function buildSourceFileNotFoundMessage(MediaFileInterface $file, ?string $localFolder, string $extra): string
    {
        return sprintf(
            'SOURCE_FILE_NOT_FOUND: relation=%s upload_file_id=%d owner_id=%d file_name="%s" '
            . 'source_folder="%s" (%s)',
            $file->getRelationType()->value,
            $file->getUploadFileId(),
            $file->getOwnerId(),
            $file->getFileName(),
            $localFolder ?? '(not configured)',
            $extra
        );
    }

    private function buildTempPath(MediaFileInterface $file): string
    {
        $tempDirectory = $this->filesystem->getDirectoryWrite(DirectoryList::TMP);

        if (!$tempDirectory->isExist(self::TEMP_SUBDIR)) {
            $tempDirectory->create(self::TEMP_SUBDIR);
        }

        $relativePath = self::TEMP_SUBDIR . '/' . $file->getUploadFileId() . '_' . uniqid('', true) . '_' . basename($file->getFileName());

        return $tempDirectory->getAbsolutePath($relativePath);
    }
}
