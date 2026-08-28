<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\ProductLink;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ProductLink\CollectionProviderInterface;

/**
 * Registered under the "connection_part" key in
 * Magento\Catalog\Model\ProductLink\CollectionProvider's providers array
 * (etc/di.xml) - required by ProductLinkQuery's single-product lookup
 * path (Magento throws NoSuchEntityException without a registered
 * provider for a custom link type). Mirrors the stock Upsell/Crosssell/
 * Related providers, which are one-line passthroughs to a
 * $product->get*Products()-style accessor; a custom link type has no such
 * accessor, so this goes directly through the generic Link model API
 * (setLinkTypeId + getProductCollection()) the same way Product's own
 * getRelatedProductCollection() etc. do internally.
 */
class ConnectionPartCollectionProvider implements CollectionProviderInterface
{
    public function getLinkedProducts(Product $product)
    {
        // Same shape as Product::getRelatedProductCollection() /
        // useRelatedLinks(), just with the custom link type id instead of
        // the LINK_TYPE_RELATED constant - setLinkTypeId() is the same
        // generic setter useRelatedLinks()/useUpSellLinks()/
        // useCrossSellLinks() call internally, so this is not bypassing
        // anything, only using it directly for a type that has no named
        // wrapper method of its own.
        $collection = $product->getLinkInstance()
            ->setLinkTypeId(ConnectionPartLinkType::LINK_TYPE_CONNECTION_PART)
            ->getProductCollection()
            ->setIsStrongMode();
        $collection->setProduct($product);

        return $collection->getItems();
    }
}
