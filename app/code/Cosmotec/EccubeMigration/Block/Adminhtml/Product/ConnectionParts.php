<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Block\Adminhtml\Product;

use Cosmotec\EccubeMigration\Plugin\AddConnectionPartsToProduct;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Registry;
use Magento\Framework\UrlInterface;

/**
 * Renders the "EC-CUBE Connection Parts" product-edit section - a
 * read-only display of dtb_coupling_product relations (Item -> specific
 * Product), visually similar to Magento's native Related Products grid
 * (thumbnail/SKU/name/price) but deliberately NOT using Magento's related
 * link mechanism, per the existing architectural decision that Connection
 * Parts is a distinct relationship (see ConnectionPartImporter). Reads
 * through AddConnectionPartsToProduct's extension data, already resolved
 * by the product-repository plugin - same pattern as AdditionalContent.
 */
class ConnectionParts extends Template
{
    public function __construct(
        Context $context,
        private readonly Registry $registry,
        private readonly ProductRepositoryInterface $productRepository,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @return array<int, array{magento_product_id: int, sku: string, name: string, thumbnail_url: ?string, edit_url: string}>
     */
    public function getConnectionParts(): array
    {
        $product = $this->registry->registry('current_product');

        if ($product === null) {
            return [];
        }

        $parts = $product->getData(AddConnectionPartsToProduct::DATA_KEY);

        if (!is_array($parts)) {
            return [];
        }

        $result = [];

        foreach ($parts as $part) {
            $connectedProductId = (int) ($part['magento_product_id'] ?? 0);

            if ($connectedProductId === 0) {
                continue;
            }

            try {
                $connectedProduct = $this->productRepository->getById($connectedProductId, false, 0);
            } catch (NoSuchEntityException) {
                continue;
            }

            $result[] = [
                'magento_product_id' => $connectedProductId,
                'sku' => $connectedProduct->getSku(),
                'name' => $connectedProduct->getName(),
                'thumbnail_url' => $this->getThumbnailUrl($connectedProduct),
                'edit_url' => $this->getUrl('catalog/product/edit', ['id' => $connectedProductId]),
            ];
        }

        return $result;
    }

    private function getThumbnailUrl(\Magento\Catalog\Api\Data\ProductInterface $product): ?string
    {
        $thumbnail = $product->getData('thumbnail');

        if ($thumbnail === null || $thumbnail === '' || $thumbnail === 'no_selection') {
            return null;
        }

        return $this->_urlBuilder->getBaseUrl(['_type' => UrlInterface::URL_TYPE_MEDIA]) . 'catalog/product' . $thumbnail;
    }
}
