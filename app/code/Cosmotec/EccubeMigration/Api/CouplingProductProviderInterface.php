<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Api;

use Cosmotec\EccubeMigration\Model\CouplingProductMap;

/**
 * Magento-side read access to a Grouped Product's Connection Parts
 * ("dtb_coupling_product" - Item -> specific Product). Deliberately a
 * distinct provider/plugin pair from ProductReferenceProvider, mirroring
 * its shape exactly, because Connection Parts is semantically distinct
 * from both product references and Related Products - see
 * ConnectionPartImporter and RelatedProductImporter.
 */
interface CouplingProductProviderInterface
{
    /**
     * @return CouplingProductMap[]
     */
    public function getByMagentoParentProductId(int $magentoParentProductId): array;
}
