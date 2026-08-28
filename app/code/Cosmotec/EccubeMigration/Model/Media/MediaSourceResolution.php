<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Media;

use Cosmotec\EccubeMigration\Model\MediaMap;

/**
 * Result of resolving one MediaFileInterface to an actual readable local
 * path - either it was already on the local EC-CUBE Image Folder Path, it
 * had to be downloaded from EC-CUBE's remote S3/CloudFront storage into a
 * temp file, or neither source has it. isTemporary() tells the caller
 * (MediaImporter) whether it owns cleanup of getAbsolutePath().
 */
final class MediaSourceResolution
{
    private function __construct(
        private readonly string $sourceType,
        private readonly ?string $absolutePath,
        private readonly bool $temporary,
        private readonly ?string $errorMessage
    ) {
    }

    public static function local(string $absolutePath): self
    {
        return new self(MediaMap::SOURCE_TYPE_LOCAL, $absolutePath, false, null);
    }

    public static function remote(string $temporaryAbsolutePath): self
    {
        return new self(MediaMap::SOURCE_TYPE_REMOTE, $temporaryAbsolutePath, true, null);
    }

    public static function notFound(string $errorMessage): self
    {
        return new self(MediaMap::SOURCE_TYPE_NOT_FOUND, null, false, $errorMessage);
    }

    public function isFound(): bool
    {
        return $this->absolutePath !== null;
    }

    public function getSourceType(): string
    {
        return $this->sourceType;
    }

    public function getAbsolutePath(): ?string
    {
        return $this->absolutePath;
    }

    public function isTemporary(): bool
    {
        return $this->temporary;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }
}
