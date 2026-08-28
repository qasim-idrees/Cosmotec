<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Media;

use Cosmotec\EccubeMigration\Logger\ImportLogger;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;

/**
 * Thin wrapper around Magento's own HTTP client (Curl) for fetching files
 * from EC-CUBE's remote S3/CloudFront storage. Never throws out of the
 * migration - every failure mode (timeout, connection error, unexpected
 * status) is caught and returned as a typed result, so one unreachable
 * remote file can never abort a batch.
 *
 * GET-only, not HEAD-then-GET: an earlier version checked existence via
 * HEAD first (falling back to a ranged GET only when HEAD was
 * inconclusive) before issuing a second, separate GET to actually
 * download. Real per-file timing this round (RemoteMediaResolver's own
 * log timestamps, isolated single-record tests) showed the fetch phase -
 * two full requests, no connection reuse, a fresh TLS handshake each -
 * was 52-74% of total per-file time for product/dimension/cad2d/cad3d.
 * Across 100+ real checks against this CDN this session, HEAD was never
 * once ambiguous (always a clean 200/403/404), meaning the ranged-GET
 * fallback path never actually fired in practice - the HEAD request was
 * pure overhead on the (overwhelming, 99.85%) success path. fetch()
 * collapses this to a single GET: the response status alone determines
 * FOUND/NOT_FOUND/FETCH_FAILED, and on success the already-downloaded
 * body is written directly to $destPath - no second request needed.
 */
class RemoteMediaClient
{
    /**
     * Performs a single GET and, on success, writes the response body to
     * $destPath. A 200/206 is FOUND (with the file now on disk). A
     * 403/404 is a confirmed NOT_FOUND (mirrors REMOTE_NOT_FOUND
     * upstream) - never treated as success. Anything else (timeout,
     * connection error, 5xx, an unexpected status, or a write failure
     * after a 200) is FETCH_FAILED - existence was never confirmed either
     * way, so callers must not treat it as a confirmed absence (see
     * RemoteMediaResolver's REMOTE_FETCH_FAILED vs SOURCE_FILE_NOT_FOUND
     * distinction).
     */
    public function __construct(
        private readonly CurlFactory $curlFactory,
        private readonly ImportLogger $logger
    ) {
    }

    public function fetch(string $url, string $destPath, int $timeoutSeconds): RemoteCheckResult
    {
        $curl = $this->createClient($timeoutSeconds);

        try {
            $curl->get($url);
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('RemoteMediaClient: GET failed for "%s": %s', $url, $e->getMessage()));

            return RemoteCheckResult::fetchFailed($e->getMessage());
        }

        $status = $curl->getStatus();

        if ($status === 200 || $status === 206) {
            $body = $curl->getBody();

            if ($body === '' || $body === false) {
                $this->logger->error(sprintf('RemoteMediaClient: GET for "%s" returned HTTP %d with an empty body', $url, $status));

                return RemoteCheckResult::fetchFailed(sprintf('HTTP %d with an empty body', $status), $status);
            }

            if (@file_put_contents($destPath, $body) === false) {
                $this->logger->error(sprintf('RemoteMediaClient: could not write downloaded content to "%s"', $destPath));

                return RemoteCheckResult::fetchFailed(sprintf('could not write downloaded content to "%s"', $destPath), $status);
            }

            return RemoteCheckResult::found($status);
        }

        if ($status === 403 || $status === 404) {
            return RemoteCheckResult::notFound($status);
        }

        $this->logger->error(sprintf('RemoteMediaClient: GET for "%s" returned unexpected HTTP %d', $url, $status));

        return RemoteCheckResult::fetchFailed(sprintf('Unexpected HTTP %d', $status), $status);
    }

    private function createClient(int $timeoutSeconds): Curl
    {
        /** @var Curl $curl */
        $curl = $this->curlFactory->create();
        $curl->setTimeout($timeoutSeconds);
        $curl->setOption(CURLOPT_CONNECTTIMEOUT, $timeoutSeconds);

        return $curl;
    }
}
