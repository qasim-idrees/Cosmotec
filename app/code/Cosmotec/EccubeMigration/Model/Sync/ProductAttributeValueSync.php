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
use Cosmotec\EccubeMigration\Model\Import\ProductAttributeValueImporter;

/**
 * Same reasoning as ItemAttributeValueSync - ProductAttributeValueImporter
 * is already hash-incremental via eccube_product_map.specification_value_hash,
 * so a full scan through getProductIdsWithValues() is already a sync.
 * Value removal is handled the same way ItemAttributeValueImporter does -
 * see ItemAttributeValueSync's docblock.
 */
class ProductAttributeValueSync implements ImporterInterface
{
    public function __construct(
        private readonly ProductAttributeValueImporter $productAttributeValueImporter
    ) {
    }

    public function import(ImportContext $context): ImportResult
    {
        return $this->productAttributeValueImporter->import($context);
    }
}
