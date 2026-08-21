<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Sync;

use Cosmotec\EccubeMigration\Model\Import\ImageImporter;
use Cosmotec\EccubeMigration\Model\Import\ImportContext;
use Cosmotec\EccubeMigration\Model\Import\ImporterInterface;
use Cosmotec\EccubeMigration\Model\Import\ImportResult;

/**
 * Milestone 8 (Synchronization). dtb_product_image has no update_date
 * column, so there is no "modified since" query to build here either —
 * ImageImporter already skips every unchanged image cheaply via its
 * filename+filesize+mtime content hash before doing any media gallery
 * write, and correctly replaces changed ones. Same reasoning as
 * InventorySync: this class exists for CLI symmetry, not to reimplement
 * anything.
 */
/**
 * @deprecated LEGACY - NOT USED BY THE PRODUCTION PIPELINE.
 *
 * This class reads dtb_product_image, which contains ZERO rows in the
 * Cosmotec production database. Real media lives in dtb_upload_file
 * joined through seven catalog relation tables.
 *
 * The production path is now:
 *   MediaReader -> MediaValidator -> MediaImporter -> eccube_media_map
 *   (commands: import:images, sync:images)
 *
 * Retained only so existing eccube_image_map rows remain readable. Do not
 * extend, and do not write new data through it.
 */
class ImageSync implements ImporterInterface
{
    public function __construct(
        private readonly ImageImporter $imageImporter
    ) {
    }

    public function import(ImportContext $context): ImportResult
    {
        return $this->imageImporter->import($context);
    }
}
