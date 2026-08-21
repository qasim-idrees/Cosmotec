<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Test\Unit\Model\Media;

use Cosmotec\EccubeMigration\Model\Media\MediaRelationType;
use PHPUnit\Framework\TestCase;

/**
 * Regression cover for the primary-image rules.
 *
 * The bug this guards against: primary selection previously depended on
 * "the Magento gallery is currently empty", so importing a dimension
 * image first permanently prevented the real product image from ever
 * receiving image/small_image/thumbnail.
 */
class PrimaryImageSelectionTest extends TestCase
{
    /**
     * Only PRODUCT and ITEM galleries have a primary image. Dimension and
     * CAD relations must never claim the primary roles, whatever their
     * ordering.
     */
    public function testOnlyGalleryRelationsCanClaimPrimaryRoles(): void
    {
        $this->assertSame('image,small_image,thumbnail', MediaRelationType::PRODUCT->magentoRole(true));
        $this->assertSame('image,small_image,thumbnail', MediaRelationType::ITEM->magentoRole(true));

        foreach ([MediaRelationType::DIMENSION, MediaRelationType::CAD2D, MediaRelationType::CAD3D] as $relation) {
            foreach ([true, false] as $claimsPrimary) {
                $role = (string) $relation->magentoRole($claimsPrimary);
                $this->assertStringNotContainsString('small_image', $role);
                $this->assertStringNotContainsString('thumbnail', $role);
                $this->assertNotSame('image,small_image,thumbnail', $role);
            }
        }
    }

    public function testDimensionKeepsItsOwnRole(): void
    {
        $this->assertSame('dimension_drawing', MediaRelationType::DIMENSION->magentoRole(true));
        $this->assertSame('dimension_drawing', MediaRelationType::DIMENSION->magentoRole(false));
    }

    /**
     * Primary resolution must be scoped to one relation, so a dimension or
     * CAD file can never influence which product_upload_file row wins.
     */
    public function testRelationsResolveIndependently(): void
    {
        $productOwners = MediaRelationType::PRODUCT->ownerEntity();
        $dimensionOwners = MediaRelationType::DIMENSION->ownerEntity();

        // Same owner entity, but they are distinct relations with distinct
        // join tables - so primary lookup cannot bleed across them.
        $this->assertSame($productOwners, $dimensionOwners);
        $this->assertNotSame(
            MediaRelationType::PRODUCT->joinTable(),
            MediaRelationType::DIMENSION->joinTable()
        );
    }

    public function testNonPrimaryGalleryImageGetsNoRoles(): void
    {
        $this->assertNull(MediaRelationType::PRODUCT->magentoRole(false));
        $this->assertNull(MediaRelationType::ITEM->magentoRole(false));
    }
}
