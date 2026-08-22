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
 * Value removal IS handled: ItemAttributeValueImporter::persist()
 * explicitly clears every eccube_spec_* attribute assigned to the
 * product's attribute set that is not present in the current resolved
 * value set (a specification removed at source correctly scrubs the
 * stale Magento value, verified after save() like every other write
 * this importer makes) - a docblock here previously claimed this was an
 * unsolved limitation; that was stale and has been corrected.
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
