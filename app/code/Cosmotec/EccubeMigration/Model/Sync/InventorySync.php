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
use Cosmotec\EccubeMigration\Model\Import\InventoryImporter;

/**
 * Milestone 8 (Synchronization). Unlike Category/Item/Product, there is no
 * meaningful "only re-scan what changed since X" query available here:
 * dtb_product_class has no update_date column, and treating
 * dtb_product.update_date as an inventory-change signal would be
 * unreliable (it changes for reasons unrelated to stock). InventoryImporter
 * already skips every unchanged record cheaply via inventory_content_hash
 * comparison before doing any MSI write, so a full scan through it *is*
 * already an incremental sync in every way that matters — this class
 * exists so sync:inventory has its own service to depend on (for CLI
 * symmetry with the other sync commands) without re-implementing anything.
 */
class InventorySync implements ImporterInterface
{
    public function __construct(
        private readonly InventoryImporter $inventoryImporter
    ) {
    }

    public function import(ImportContext $context): ImportResult
    {
        return $this->inventoryImporter->import($context);
    }
}
