<?php
namespace Cosmotec\ProductView\Block;

use Magento\Framework\View\Element\Template;
use Magento\Framework\Registry;
use Magento\Framework\DataObject\IdentityInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Type;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\GroupedProduct\Model\Product\Type\Grouped;
use Magento\Bundle\Model\Product\Type as Bundle;
use Magento\Downloadable\Model\Product\Type as Downloadable;

class BasicInformation extends Template implements IdentityInterface
{
    protected $registry;

    public function __construct(
        Template\Context $context,
        Registry $registry,
        array $data = []
    ) {
        $this->registry = $registry;
        parent::__construct($context, $data);
    }

    /**
     * Get the current product from registry
     */
    public function getProduct()
    {
        return $this->registry->registry('current_product');
    }

    /**
     * Return unique ID(s) for the product to handle cache invalidation
     * and ensure the FPC treats each product block as unique.
     */
    public function getIdentities()
    {
        $product = $this->getProduct();
        if ($product) {
            return [Product::CACHE_TAG . '_' . $product->getId()];
        }
        return [Product::CACHE_TAG];
    }

    /**
     * Set the cache key information based on the product ID
     */
    public function getCacheKeyInfo()
    {
        $product = $this->getProduct();
        return [
            'COSMOTEC_BASIC_INFO_BLOCK',
            $this->_storeManager->getStore()->getId(),
            $this->_design->getDesignTheme()->getId(),
            $this->getTemplate(),
            $product ? $product->getId() : 'no_product'
        ];
    }

    public function getProductType(Product $product)
    {
        switch ($product->getTypeId()) {
            case Type::TYPE_SIMPLE:
                // Handle Simple Product
                break;
        
            case Type::TYPE_VIRTUAL:
                // Handle Virtual Product (e.g., Service, Warranty)
                break;
        
            case Configurable::TYPE_CODE:
                // Handle Configurable Product (e.g., T-shirt with sizes)
                break;
        
            case Grouped::TYPE_CODE:
                // Handle Grouped Product (e.g., Set of tools)
                break;
        
            case Bundle::TYPE_CODE:
                // Handle Bundle Product (e.g., Build-your-own PC)
                break;
        
            case Downloadable::TYPE_DISTRIBUTED:
                // Handle Downloadable Product (e.g., Software, E-book)
                break;
        
            default:
                // Handle custom or unknown product types
                break;
        }
        return $product->getTypeId();
    }

    public function isSimpleProduct(Product $product)
    {
        return Type::TYPE_SIMPLE == $product->getTypeId() ? true : false;

    }

    public function isGroupProduct(Product $product)
    {
        return Grouped::TYPE_CODE == $product->getTypeId() ? true : false;

    }

    public function isConfigurableProduct(Product $product)
    {
        return Configurable::TYPE_CODE == $product->getTypeId() ? true : false;

    }
}
