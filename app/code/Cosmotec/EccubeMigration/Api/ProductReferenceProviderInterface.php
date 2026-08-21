<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Api;

use Cosmotec\EccubeMigration\Model\ProductReferenceMap;

/**
 * Magento-side read access to a Simple Product's document references.
 *
 * Consumer-facing counterpart of ProductReferenceImporter: blocks,
 * templates and API consumers read references through this service
 * rather than reaching into the mapping table directly.
 *
 * The 1:N source model is preserved end to end - a product may carry any
 * number of references, each keeping its own source id, name, link and
 * ordering. References are deliberately NOT flattened into numbered EAV
 * attributes.
 */
interface ProductReferenceProviderInterface
{
    /**
     * @return ProductReferenceMap[]
     */
    public function getByMagentoProductId(int $magentoProductId): array;

    /**
     * @param int[] $magentoProductIds
     * @return array<int, ProductReferenceMap[]>
     */
    public function getByMagentoProductIds(array $magentoProductIds): array;
}
