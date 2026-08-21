<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Test\Unit\Model\Media;

use Cosmotec\EccubeMigration\Model\Media\MediaClass;
use Cosmotec\EccubeMigration\Model\Media\MediaRelationType;
use PHPUnit\Framework\TestCase;

class MediaRelationTypeTest extends TestCase
{
    /**
     * Join table names verified against the production DDL.
     */
    public function testJoinTablesMatchSource(): void
    {
        $this->assertSame('product_upload_file', MediaRelationType::PRODUCT->joinTable());
        $this->assertSame('dimension_upload_file', MediaRelationType::DIMENSION->joinTable());
        $this->assertSame('cad2d_upload_file', MediaRelationType::CAD2D->joinTable());
        $this->assertSame('cad3d_upload_file', MediaRelationType::CAD3D->joinTable());
        $this->assertSame('item_upload_file', MediaRelationType::ITEM->joinTable());
        $this->assertSame('catalog_upload_file', MediaRelationType::CATALOG->joinTable());
        $this->assertSame('category_upload_file', MediaRelationType::CATEGORY->joinTable());
    }

    /**
     * Ownership isolation: item/catalog media belongs to the Grouped
     * Product and must never be attributed to a Simple Product, and vice
     * versa.
     */
    public function testOwnershipIsolation(): void
    {
        foreach ([MediaRelationType::PRODUCT, MediaRelationType::DIMENSION, MediaRelationType::CAD2D, MediaRelationType::CAD3D] as $childRelation) {
            $this->assertSame('simple_product', $childRelation->magentoEntityType());
            $this->assertSame('product', $childRelation->ownerEntity());
        }

        foreach ([MediaRelationType::ITEM, MediaRelationType::CATALOG] as $parentRelation) {
            $this->assertSame('grouped_product', $parentRelation->magentoEntityType());
            $this->assertSame('item', $parentRelation->ownerEntity());
        }

        $this->assertSame('category', MediaRelationType::CATEGORY->magentoEntityType());
    }

    /**
     * Dimension drawings and CAD documents must never claim the main
     * image roles, however they are ordered.
     */
    public function testOnlyGalleryRelationsClaimMainImageRoles(): void
    {
        $this->assertSame('image,small_image,thumbnail', MediaRelationType::PRODUCT->magentoRole(true));
        $this->assertSame('image,small_image,thumbnail', MediaRelationType::ITEM->magentoRole(true));
        $this->assertNull(MediaRelationType::PRODUCT->magentoRole(false));

        foreach ([MediaRelationType::DIMENSION, MediaRelationType::CAD2D, MediaRelationType::CAD3D] as $relation) {
            foreach ([true, false] as $isFirst) {
                $role = $relation->magentoRole($isFirst);
                $this->assertNotNull($role);
                $this->assertStringNotContainsString('small_image', $role);
                $this->assertStringNotContainsString('thumbnail', $role);
            }
        }

        $this->assertSame('dimension_drawing', MediaRelationType::DIMENSION->magentoRole(true));
    }

    /**
     * A valid PDF/DXF/STP arriving through a CAD relation must not be
     * rejected merely for not being an image.
     */
    public function testOnlyGalleryRelationsRequireImages(): void
    {
        $this->assertTrue(MediaRelationType::PRODUCT->requiresImage());
        $this->assertTrue(MediaRelationType::DIMENSION->requiresImage());
        $this->assertTrue(MediaRelationType::ITEM->requiresImage());
        $this->assertTrue(MediaRelationType::CATEGORY->requiresImage());

        $this->assertFalse(MediaRelationType::CAD2D->requiresImage());
        $this->assertFalse(MediaRelationType::CAD3D->requiresImage());
        $this->assertFalse(MediaRelationType::CATALOG->requiresImage());
    }

    /**
     * Customer/session and transactional relations must never appear as
     * catalog media relations.
     */
    public function testNonCatalogRelationsAreAbsent(): void
    {
        $tables = array_map(static fn (MediaRelationType $t): string => $t->joinTable(), MediaRelationType::all());

        $this->assertNotContains('contact_upload_file', $tables);
        $this->assertNotContains('designated_slip_upload_file', $tables);
        $this->assertNotContains('dtb_upload_cad_zip_file', $tables);
        $this->assertNotContains('dtb_product_image', $tables);
        $this->assertCount(7, $tables);
    }

    public function testMediaClassification(): void
    {
        $this->assertSame(MediaClass::IMAGE, MediaClass::fromExtension('jpg'));
        $this->assertSame(MediaClass::IMAGE, MediaClass::fromExtension('PNG'));
        $this->assertSame(MediaClass::DOCUMENT, MediaClass::fromExtension('pdf'));
        $this->assertSame(MediaClass::CAD, MediaClass::fromExtension('dxf'));
        $this->assertSame(MediaClass::CAD, MediaClass::fromExtension('stp'));
        $this->assertSame(MediaClass::CAD, MediaClass::fromExtension('step'));
        $this->assertSame(MediaClass::ARCHIVE, MediaClass::fromExtension('zip'));
        $this->assertSame(MediaClass::OTHER, MediaClass::fromExtension('xyz'));
    }
}
