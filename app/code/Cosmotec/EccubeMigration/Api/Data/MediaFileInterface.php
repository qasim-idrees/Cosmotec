<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Api\Data;

use Cosmotec\EccubeMigration\Model\Media\MediaClass;
use Cosmotec\EccubeMigration\Model\Media\MediaRelationType;

/**
 * One dtb_upload_file row resolved through a specific relation table.
 * The same physical file could in principle appear under more than one
 * relation, so identity is (relation type, owner id, upload file id).
 */
interface MediaFileInterface
{
    public function getUploadFileId(): int;

    public function getRelationType(): MediaRelationType;

    public function getOwnerId(): int;

    public function getFileName(): string;

    public function getSortNo(): int;

    public function getExtension(): string;

    public function getMediaClass(): MediaClass;

    /**
     * Absolute path under the configured EC-CUBE image folder
     * (html/upload/save_image/{file_name}).
     */
    public function getAbsolutePath(string $baseFolder): string;
}
