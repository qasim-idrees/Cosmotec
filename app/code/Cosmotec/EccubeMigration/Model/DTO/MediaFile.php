<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\DTO;

use Cosmotec\EccubeMigration\Api\Data\MediaFileInterface;
use Cosmotec\EccubeMigration\Model\Media\MediaClass;
use Cosmotec\EccubeMigration\Model\Media\MediaRelationType;

final class MediaFile implements MediaFileInterface
{
    private readonly string $extension;
    private readonly MediaClass $mediaClass;

    public function __construct(
        private readonly int $uploadFileId,
        private readonly MediaRelationType $relationType,
        private readonly int $ownerId,
        private readonly string $fileName,
        private readonly int $sortNo
    ) {
        $this->extension = strtolower((string) pathinfo($this->fileName, PATHINFO_EXTENSION));
        $this->mediaClass = MediaClass::fromExtension($this->extension);
    }

    public function getUploadFileId(): int
    {
        return $this->uploadFileId;
    }

    public function getRelationType(): MediaRelationType
    {
        return $this->relationType;
    }

    public function getOwnerId(): int
    {
        return $this->ownerId;
    }

    public function getFileName(): string
    {
        return $this->fileName;
    }

    public function getSortNo(): int
    {
        return $this->sortNo;
    }

    public function getExtension(): string
    {
        return $this->extension;
    }

    public function getMediaClass(): MediaClass
    {
        return $this->mediaClass;
    }

    public function getAbsolutePath(string $baseFolder): string
    {
        return rtrim($baseFolder, '/') . '/' . ltrim($this->fileName, '/');
    }
}
