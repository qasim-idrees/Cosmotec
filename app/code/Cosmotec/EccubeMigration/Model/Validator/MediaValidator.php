<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Validator;

use Cosmotec\EccubeMigration\Api\Data\MediaFileInterface;
use Cosmotec\EccubeMigration\Api\EccubeConfigProviderInterface;

/**
 * Relation-aware media validation.
 *
 * Replaces the image-only assumption of ImageValidator: dtb_upload_file
 * is a general document repository, so a valid PDF/DXF/STP arriving via
 * a CAD relation must pass, while a non-image arriving via a gallery
 * relation must fail.
 */
class MediaValidator implements ValidatorInterface
{
    public function __construct(
        private readonly EccubeConfigProviderInterface $config
    ) {
    }

    /**
     * $resolvedAbsolutePath, when passed, is trusted as the already-
     * resolved file to validate (local OR a temp download from remote
     * media fallback - see MediaImporter/RemoteMediaResolver) instead of
     * this method recomputing a local-only path itself. Omitting it
     * preserves this method's original, unchanged local-only behavior -
     * ValidatorInterface::validate(object $source) callers elsewhere are
     * unaffected.
     */
    public function validate(object $source, ?string $resolvedAbsolutePath = null): ValidationResult
    {
        if (!$source instanceof MediaFileInterface) {
            return ValidationResult::failure([
                sprintf('Expected %s, got %s', MediaFileInterface::class, get_debug_type($source)),
            ]);
        }

        $errors = [];

        if (trim($source->getFileName()) === '') {
            return ValidationResult::failure([
                sprintf('Upload file %d has an empty file_name', $source->getUploadFileId()),
            ]);
        }

        $folder = $this->config->getImageFolder();

        if ($resolvedAbsolutePath === null && $folder === null) {
            return ValidationResult::failure([
                'EC-CUBE Image Folder Path is not configured (Stores > Configuration > Cosmotec > EC-CUBE Migration).',
            ]);
        }

        $path = $resolvedAbsolutePath ?? $source->getAbsolutePath((string) $folder);

        if (!is_file($path)) {
            // The EC-CUBE database filename is authoritative. A genuinely
            // missing file is reported with full context and never masked,
            // renamed, substituted or silently skipped.
            return ValidationResult::failure([
                sprintf(
                    'SOURCE_FILE_NOT_FOUND: relation=%s upload_file_id=%d owner_id=%d '
                    . 'file_name="%s" source_folder="%s" resolved_path="%s"',
                    $source->getRelationType()->value,
                    $source->getUploadFileId(),
                    $source->getOwnerId(),
                    $source->getFileName(),
                    $folder ?? '(not configured)',
                    $path
                ),
            ]);
        }

        if (!is_readable($path)) {
            return ValidationResult::failure([
                sprintf(
                    'SOURCE_FILE_NOT_READABLE: relation=%s upload_file_id=%d owner_id=%d '
                    . 'resolved_path="%s" - the file exists but the PHP user cannot read it '
                    . '(check filesystem permissions/ownership)',
                    $source->getRelationType()->value,
                    $source->getUploadFileId(),
                    $source->getOwnerId(),
                    $path
                ),
            ]);
        }

        // Only gallery-style relations demand a renderable image. CAD and
        // catalog documents are legitimately pdf/dxf/stp/step.
        if ($source->getRelationType()->requiresImage() && !$source->getMediaClass()->isImage()) {
            $errors[] = sprintf(
                'Upload file %d is a %s (.%s) but relation "%s" requires an image',
                $source->getUploadFileId(),
                $source->getMediaClass()->value,
                $source->getExtension(),
                $source->getRelationType()->value
            );
        }

        return $errors === [] ? ValidationResult::success() : ValidationResult::failure($errors);
    }
}
