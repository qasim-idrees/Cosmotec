<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Sync;

use Cosmotec\EccubeMigration\Model\Import\AttributeSetImporter;
use Cosmotec\EccubeMigration\Model\Import\ImportContext;
use Cosmotec\EccubeMigration\Model\Import\ImporterInterface;
use Cosmotec\EccubeMigration\Model\Import\ImportResult;

/**
 * AttributeSetImporter already skips a top-level category whose assigned
 * specification-code set hash is unchanged, and re-assigns (idempotently)
 * whenever it grows - a full scan through the 8 top-level categories is
 * already an incremental sync. Same reasoning as AttributeSync.
 */
class AttributeSetSync implements ImporterInterface
{
    public function __construct(
        private readonly AttributeSetImporter $attributeSetImporter
    ) {
    }

    public function import(ImportContext $context): ImportResult
    {
        return $this->attributeSetImporter->import($context);
    }
}
