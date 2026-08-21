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
 * Physical file classification derived from the file extension.
 *
 * Classification decides HOW a file is handled; the relation table
 * decides WHO owns it and what role it plays. The two are deliberately
 * independent: the same .pdf is a CAD-tab document when it arrives via
 * cad2d_upload_file and a catalog document via catalog_upload_file.
 */
enum MediaClass: string
{
    case IMAGE = 'IMAGE';
    case DOCUMENT = 'DOCUMENT';
    case CAD = 'CAD';
    case ARCHIVE = 'ARCHIVE';
    case OTHER = 'OTHER';

    public static function fromExtension(string $extension): self
    {
        return match (strtolower(trim($extension, '. '))) {
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp' => self::IMAGE,
            'pdf', 'xlsx', 'xls', 'pptx', 'docx', 'doc', 'csv', 'txt' => self::DOCUMENT,
            'dxf', 'stp', 'step', 'igs', 'iges', 'sldprt', 'dwg' => self::CAD,
            'zip', 'rar', '7z', 'tar', 'gz' => self::ARCHIVE,
            default => self::OTHER,
        };
    }

    public function isImage(): bool
    {
        return $this === self::IMAGE;
    }
}
