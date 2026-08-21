<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Sync;

use Cosmotec\EccubeMigration\Model\Import\AttributeImporter;
use Cosmotec\EccubeMigration\Model\Import\ImportContext;
use Cosmotec\EccubeMigration\Model\Import\ImporterInterface;
use Cosmotec\EccubeMigration\Model\Import\ImportResult;

/**
 * dtb_specification has no reliable "modified since" column to build an
 * incremental scan on, and AttributeImporter already adopts an existing
 * Magento attribute with a matching code rather than duplicating it, and
 * only creates options that don't already have a mapping - a full scan
 * through it already behaves as an incremental sync in every way that
 * matters. Same reasoning as InventorySync/ImageSync (Milestone 8):
 * delegating honestly rather than inventing a fake distinction.
 */
class AttributeSync implements ImporterInterface
{
    public function __construct(
        private readonly AttributeImporter $attributeImporter
    ) {
    }

    public function import(ImportContext $context): ImportResult
    {
        return $this->attributeImporter->import($context);
    }
}
