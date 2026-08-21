<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Plugin;

use Cosmotec\EccubeMigration\Api\ProductReferenceProviderInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;

/**
 * Surfaces EC-CUBE document references on the Magento product so blocks,
 * templates and API consumers can read them from the product they already
 * have.
 *
 * References remain a true 1:N relation in eccube_product_reference_map;
 * this plugin only exposes them for reading and never flattens them.
 */
class AddProductReferencesToProduct
{
    public const DATA_KEY = 'eccube_product_references';

    public function __construct(
        private readonly ProductReferenceProviderInterface $referenceProvider
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

        if ($productId === 0) {
            return $product;
        }

        $references = [];

        foreach ($this->referenceProvider->getByMagentoProductId($productId) as $map) {
            $references[] = [
                'source_reference_id' => (int) $map->getEccubeReferenceId(),
                'name' => $map->getReferenceName(),
                'link' => $map->getReferenceLink(),
                'sort_no' => (int) $map->getSortNo(),
            ];
        }

        $product->setData(self::DATA_KEY, $references);

        return $product;
    }
}
