<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Plugin;

use Cosmotec\EccubeMigration\Api\CouplingProductProviderInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;

/**
 * Surfaces EC-CUBE Connection Parts (dtb_coupling_product) on the Grouped
 * Product so blocks, templates and API consumers can read them from the
 * product they already have - mirrors AddProductReferencesToProduct
 * exactly, kept as a separate plugin because Connection Parts is a
 * distinct relationship from product references and from Related
 * Products (see ConnectionPartImporter / RelatedProductImporter).
 */
class AddConnectionPartsToProduct
{
    public const DATA_KEY = 'eccube_connection_parts';

    public function __construct(
        private readonly CouplingProductProviderInterface $couplingProductProvider
    ) {
    }

    public function afterGet(ProductRepositoryInterface $subject, ProductInterface $result): ProductInterface
    {
        return $this->attach($result);
    }

    public function afterGetById(ProductRepositoryInterface $subject, ProductInterface $result): ProductInterface
    {
        return $this->attach($result);
    }

    private function attach(ProductInterface $product): ProductInterface
    {
        $productId = (int) $product->getId();

        if ($productId === 0 || $product->getTypeId() !== 'grouped') {
            return $product;
        }

        $parts = [];

        foreach ($this->couplingProductProvider->getByMagentoParentProductId($productId) as $map) {
            $parts[] = [
                'source_coupling_id' => (int) $map->getEccubeCouplingId(),
                'magento_product_id' => (int) $map->getMagentoConnectedProductId(),
                'sort_no' => (int) $map->getSortNo(),
            ];
        }

        $product->setData(self::DATA_KEY, $parts);

        return $product;
    }
}
