<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Sync;

use Cosmotec\EccubeMigration\Model\Import\ImportContext;
use Cosmotec\EccubeMigration\Model\Import\ImporterInterface;
use Cosmotec\EccubeMigration\Model\Import\ImportResult;
use Cosmotec\EccubeMigration\Model\Import\ItemAttributeValueImporter;

/**
 * ItemAttributeValueImporter already skips an item whose resolved
 * attribute-value set hash (eccube_item_map.specification_value_hash)
 * is unchanged - a full scan is already an incremental sync. Same
 * reasoning as InventorySync/ImageSync.
 *
 * KNOWN LIMITATION (not solved by this class, stated explicitly rather
 * than silently omitted): if a value is REMOVED at source (an item stops
 * declaring a specification it previously had), the hash-based skip
 * correctly detects the change and re-runs the write, but the write only
 * ever sets attribute values present in the resolved set - it does not
 * explicitly clear a Magento attribute value for a specification the
 * item no longer has. This mirrors a real, currently-unsolved gap in the
 * existing ProductReferenceImporter/RelatedProductImporter/ConnectionPartImporter
 * "obsolete" methods, which mark the *mapping* obsolete but do not scrub
 * already-written Magento data either. Revisit if source deletions of
 * individual specification values (not whole items) turn out to occur in
 * practice.
 */
class ItemAttributeValueSync implements ImporterInterface
{
    public function __construct(
        private readonly ItemAttributeValueImporter $itemAttributeValueImporter
    ) {
    }

    public function import(ImportContext $context): ImportResult
    {
        return $this->itemAttributeValueImporter->import($context);
    }
}
