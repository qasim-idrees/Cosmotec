<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Test\Unit\Model\DTO;

use Cosmotec\EccubeMigration\Model\DTO\MediaFile;
use Cosmotec\EccubeMigration\Model\Media\MediaClass;
use Cosmotec\EccubeMigration\Model\Media\MediaRelationType;
use PHPUnit\Framework\TestCase;

class MediaFileTest extends TestCase
{
    public function testDerivesExtensionAndClass(): void
    {
        $file = new MediaFile(101, MediaRelationType::CAD3D, 19860, '10298_d-abc123.stp', 0);

        $this->assertSame('stp', $file->getExtension());
        $this->assertSame(MediaClass::CAD, $file->getMediaClass());
    }

    /**
     * Path is base folder + stored filename; the filename already carries
     * EC-CUBE's uniqueness hash and there are no hashed subdirectories.
     */
    public function testAbsolutePathResolution(): void
    {
        $file = new MediaFile(1, MediaRelationType::PRODUCT, 5, '10298_p-abc.jpg', 0);

        $this->assertSame(
            '/var/www/html/upload/save_image/10298_p-abc.jpg',
            $file->getAbsolutePath('/var/www/html/upload/save_image')
        );
        $this->assertSame(
            '/var/www/html/upload/save_image/10298_p-abc.jpg',
            $file->getAbsolutePath('/var/www/html/upload/save_image/')
        );
    }

    public function testRetainsSourceIdentity(): void
    {
        $file = new MediaFile(42, MediaRelationType::DIMENSION, 777, 'x_d-1.png', 3);

        $this->assertSame(42, $file->getUploadFileId());
        $this->assertSame(777, $file->getOwnerId());
        $this->assertSame(3, $file->getSortNo());
        $this->assertSame(MediaRelationType::DIMENSION, $file->getRelationType());
    }

    public function testFileWithoutExtension(): void
    {
        $file = new MediaFile(1, MediaRelationType::PRODUCT, 1, 'noextension', 0);

        $this->assertSame('', $file->getExtension());
        $this->assertSame(MediaClass::OTHER, $file->getMediaClass());
    }
}
