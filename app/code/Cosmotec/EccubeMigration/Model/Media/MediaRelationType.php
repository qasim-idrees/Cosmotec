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
 * The EC-CUBE UploadFile relation tables that carry catalog media.
 *
 * Source-confirmed inventory (production row counts in brackets). Two
 * further relations exist but are deliberately absent because they are
 * not catalog data: contact_upload_file (Contact, 1,341) and
 * designated_slip_upload_file (Order, 197). dtb_upload_cad_zip_file is
 * likewise excluded - it is customer/session generated.
 */
enum MediaRelationType: string
{
    case PRODUCT = 'product';       // product_upload_file    [30,876] -> Simple Product gallery
    case DIMENSION = 'dimension';   // dimension_upload_file  [26,417] -> Simple Product, dimension_drawing role
    case CAD3D = 'cad3d';           // cad3d_upload_file      [10,655] -> Simple Product CAD document
    case CAD2D = 'cad2d';           // cad2d_upload_file       [1,420] -> Simple Product CAD document
    case ITEM = 'item';             // item_upload_file        [1,093] -> Grouped Product media
    case CATEGORY = 'category';     // category_upload_file      [325] -> Magento category image
    case CATALOG = 'catalog';       // catalog_upload_file       [286] -> Grouped Product document

    public function joinTable(): string
    {
        return match ($this) {
            self::PRODUCT => 'product_upload_file',
            self::DIMENSION => 'dimension_upload_file',
            self::CAD3D => 'cad3d_upload_file',
            self::CAD2D => 'cad2d_upload_file',
            self::ITEM => 'item_upload_file',
            self::CATEGORY => 'category_upload_file',
            self::CATALOG => 'catalog_upload_file',
        };
    }

    /**
     * Which EC-CUBE entity owns this relation. Ownership comes from the
     * relation table, never from the file extension.
     */
    public function ownerEntity(): string
    {
        return match ($this) {
            self::PRODUCT, self::DIMENSION, self::CAD3D, self::CAD2D => 'product',
            self::ITEM, self::CATALOG => 'item',
            self::CATEGORY => 'category',
        };
    }

    public function magentoEntityType(): string
    {
        return match ($this) {
            self::PRODUCT, self::DIMENSION, self::CAD3D, self::CAD2D => 'simple_product',
            self::ITEM, self::CATALOG => 'grouped_product',
            self::CATEGORY => 'category',
        };
    }

    /**
     * Magento media role. Only PRODUCT and ITEM galleries may claim the
     * main image roles; dimension drawings and CAD documents must never
     * become image/small_image/thumbnail.
     */
    public function magentoRole(bool $isFirst): ?string
    {
        return match ($this) {
            self::PRODUCT, self::ITEM => $isFirst ? 'image,small_image,thumbnail' : null,
            self::DIMENSION => 'dimension_drawing',
            self::CAD2D => 'cad2d',
            self::CAD3D => 'cad3d',
            self::CATALOG => 'catalog',
            self::CATEGORY => 'category_image',
        };
    }

    /**
     * True when the payload must be a renderable image. CAD documents and
     * catalog files must not be rejected for failing image validation.
     */
    public function requiresImage(): bool
    {
        return match ($this) {
            self::PRODUCT, self::DIMENSION, self::ITEM, self::CATEGORY => true,
            self::CAD2D, self::CAD3D, self::CATALOG => false,
        };
    }

    /**
     * @return self[]
     */
    public static function all(): array
    {
        return self::cases();
    }
}
