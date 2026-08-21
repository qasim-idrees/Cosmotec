<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Api\Data;

interface MagentoImageInterface
{
    public function getEccubeImageId(): int;

    public function getEccubeProductId(): int;

    public function getAbsolutePath(): string;

    public function getFileName(): string;

    /**
     * True if this image should hold the image/small_image/thumbnail
     * roles; false if it's gallery-only.
     */
    public function isMain(): bool;

    public function getPosition(): int;

    /**
     * Hash of filename+filesize+mtime — a fast, IO-cheap proxy for "did the
     * file content change" without reading and hashing every image's full
     * binary content on every validate/import run.
     */
    public function getContentHash(): string;
}
