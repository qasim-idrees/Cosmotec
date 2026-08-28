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
 * Builds the URL EC-CUBE's own remote S3/CloudFront storage serves a
 * dtb_upload_file record's binary from - see AwsS3FileUpload/
 * TwigFormExtension/S3Constant on the live EC-CUBE application, and
 * app/config/eccube/packages/framework.yaml's asset package list, all
 * inspected read-only (docs/media investigation) before this class was
 * written. Never invents a naming convention of its own.
 *
 * Two remote packages exist, and only these two are relevant to catalog
 * media (AwsS3FileUpload::S3_TARGET_PATH_MAP):
 *  - save_image (/html/upload/save_image/) - all image relations.
 *  - save_zip (/html/upload/save_zip/) - CAD 2D/3D archives. Live-confirmed
 *    during investigation: the same file returns 403 under save_image and
 *    200 under save_zip - the packages are NOT interchangeable.
 *
 * The database stores only the ORIGINAL (un-sized) filename
 * (dtb_upload_file.file_name / eccube_media_map.source_file_name) - this
 * is also exactly what MediaImporter's whole pipeline already expects to
 * read and import (confirmed: every eccube_media_map.source_file_name
 * sampled during investigation was the bare original, never a
 * "-150-150-"/"-300-300-" sized variant). buildOriginalUrl() is therefore
 * what the resolution pipeline actually uses. buildVariantFilename()
 * reproduces EC-CUBE's own AwsS3FileUpload::getS3Url()/getFileNameList()
 * naming transformation (S3Constant::THUMBNAIL_SIZE_MAP) for completeness/
 * parity, in case a future need arises for a specific sized variant - it
 * is not used by the recovery flow itself, since Magento generates its
 * own thumbnails natively from the original (CLAUDE.md "Images": do not
 * copy unnecessary generated variants EC-CUBE already produced for its
 * own storefront if Magento can generate appropriate sizes natively).
 */
class RemoteMediaLocator
{
    private const PACKAGE_SAVE_IMAGE = 'save_image';
    private const PACKAGE_SAVE_ZIP = 'save_zip';

    /**
     * EC-CUBE's own single-character variant prefixes (S3Constant::
     * IMAGE_PREFIX_MAP) reproduced here only to replicate
     * AwsS3FileUpload's regex faithfully - not to reinterpret it.
     */
    private const VARIANT_FILENAME_PATTERN = '/^(?<originName>.*)_(?<imgPrefix>[cipld])-(?<uniqName>.*)$/u';

    /**
     * S3Constant::THUMBNAIL_SIZE_MAP, ported verbatim.
     */
    private const THUMBNAIL_SIZE_MAP = [
        's' => [150, 150],
        'm' => [300, 300],
        'l' => [1000, 1000],
    ];

    public function getPackageFor(MediaRelationType $relationType): string
    {
        return match ($relationType) {
            MediaRelationType::CAD2D, MediaRelationType::CAD3D => self::PACKAGE_SAVE_ZIP,
            default => self::PACKAGE_SAVE_IMAGE,
        };
    }

    /**
     * Builds the URL for the ORIGINAL (as-stored) filename - what
     * MediaImporter actually needs to read and import.
     */
    public function buildOriginalUrl(string $baseUrl, MediaRelationType $relationType, string $fileName): string
    {
        return $this->buildUrl($baseUrl, $relationType, $fileName);
    }

    /**
     * Reproduces AwsS3FileUpload::getS3Url()'s filename transformation:
     * insert the size's pixel dimensions after the single-character type
     * prefix. Returns null when $fileName doesn't match EC-CUBE's own
     * variant-naming pattern (e.g. CAD archives, which never have size
     * variants) or $size is not one of 's'/'m'/'l'.
     */
    public function buildVariantFilename(string $fileName, string $size): ?string
    {
        $size = strtolower($size);

        if (!isset(self::THUMBNAIL_SIZE_MAP[$size])) {
            return null;
        }

        if (preg_match(self::VARIANT_FILENAME_PATTERN, $fileName, $matches) !== 1) {
            return null;
        }

        [$width, $height] = self::THUMBNAIL_SIZE_MAP[$size];

        return sprintf('%s_%s-%d-%d-%s', $matches['originName'], $matches['imgPrefix'], $width, $height, $matches['uniqName']);
    }

    public function buildUrl(string $baseUrl, MediaRelationType $relationType, string $fileName): string
    {
        return sprintf(
            '%s/html/upload/%s/%s',
            rtrim($baseUrl, '/'),
            $this->getPackageFor($relationType),
            rawurlencode($fileName)
        );
    }
}
