<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Media;

/**
 * Outcome of a single remote-existence check against the EC-CUBE CDN.
 * Deliberately distinguishes a confirmed absence (FOUND=false,
 * transient=false - a real 403/404) from an inconclusive one
 * (transient=true - timeout, connection error, 5xx, or an unexpected
 * status neither HEAD nor a ranged GET could resolve), so the caller can
 * tell "genuinely not on the CDN" apart from "could not reach the CDN
 * right now" per the REMOTE_NOT_FOUND / REMOTE_FETCH_FAILED distinction.
 */
final class RemoteCheckResult
{
    private function __construct(
        private readonly bool $found,
        private readonly bool $transient,
        private readonly ?int $httpStatus,
        private readonly ?string $message
    ) {
    }

    public static function found(int $httpStatus): self
    {
        return new self(true, false, $httpStatus, null);
    }

    public static function notFound(int $httpStatus): self
    {
        return new self(false, false, $httpStatus, sprintf('HTTP %d', $httpStatus));
    }

    public static function fetchFailed(string $message, ?int $httpStatus = null): self
    {
        return new self(false, true, $httpStatus, $message);
    }

    public function isFound(): bool
    {
        return $this->found;
    }

    /**
     * True when the check itself could not be completed (network error,
     * timeout, unexpected status) - the file's real existence is unknown,
     * as opposed to a confirmed 403/404.
     */
    public function isTransient(): bool
    {
        return $this->transient;
    }

    public function getHttpStatus(): ?int
    {
        return $this->httpStatus;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }
}
