<?php
namespace Cosmotec\VariantFilter\Block\Product;

use Magento\Framework\DataObject\IdentityInterface;
use Magento\Eav\Model\ResourceModel\Entity\Attribute\Group\CollectionFactory as AttributeGroupCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory as AttributeCollectionFactory;
use Magento\Framework\View\Element\Template;
use Magento\Framework\Registry;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\GroupedProduct\Model\Product\Type\Grouped;
use Magento\Bundle\Model\Product\Type as Bundle;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Api\ProductRepositoryInterface;

class VariantFilter extends Template implements IdentityInterface
{
    protected $registry;
    protected $configurableType;
    protected $groupedType;
    protected $bundleType;
    protected $productRepository;

    protected $_attributeGroupCollectionFactory;
    protected $_attributeCollectionFactory;
    protected $_skipGroups = ['Product Details'];
    protected $_skipAttributes = [];

    /**
     * Cache tag value
     */
    public const CACHE_TAG = 'variantfilter_block';


    public function __construct(
        Template\Context $context,
        Registry $registry,
        Configurable $configurableType,
        Grouped $groupedType,
        Bundle $bundleType,
        ProductRepositoryInterface $productRepository,
        AttributeGroupCollectionFactory $attributeGroupCollectionFactory,
        AttributeCollectionFactory $attributeCollectionFactory,
        array $data = []
    ) {
        $this->registry = $registry;
        $this->configurableType = $configurableType;
        $this->groupedType = $groupedType;
        $this->bundleType = $bundleType;
        $this->productRepository = $productRepository;
        $this->_attributeGroupCollectionFactory = $attributeGroupCollectionFactory;
        $this->_attributeCollectionFactory = $attributeCollectionFactory;
        parent::__construct($context, $data);
        $this->addData([
            'cache_lifetime' => 3600
        ]);
    }

    public function getProduct() {
        return $this->registry->registry('current_product');
    }

    /**
     * UNIQUE CACHE KEY
     * This ensures Magento generates a different cache file for every product.
     */
    public function getCacheKeyInfo()
    {
        $product = $this->getProduct();
        return [
            'COSMOTEC_VARIANT_FILTER_BLOCK',
            $this->_storeManager->getStore()->getId(),
            $this->_design->getDesignTheme()->getId(),
            $this->getTemplate(),
            $product ? $product->getId() : 'no_product'
        ];
    }

    /**
     * DYNAMIC IDENTITIES
     * This tells Magento to clear this block if the specific product is updated.
     */
    public function getIdentities()
    {
        $product = $this->getProduct();
        if ($product) {
            return [
                Product::CACHE_TAG . '_' . $product->getId(),
                'variantfilter_block' 
            ];
        }
        return ['variantfilter_block'];
    }

    public function getProductPrice(Product $product) {
        return $product->getFormattedPrice();
    }

    public function getChildProductsBKP() {
        return $this->configurable->getUsedProducts($this->getProduct());
    }
    
    public function getChildProducts(Product $product): array
    {
        switch ($product->getTypeId()) {

            case Configurable::TYPE_CODE:
                return $this->getConfigurableChildren($product);

            case Grouped::TYPE_CODE:
                return $this->getGroupedChildren($product);

            default:
                return [];
        }
    }

    protected function getConfigurableChildren(Product $product): array
    {
        // IMPORTANT: preload configurable attributes
        //$attributes = $this->configurableType->getConfigurableAttributes($product);
        
        ///$this->configurableType->setUsedProductAttributes($product, $attributes);

        // Now children will contain ALL needed attributes
        return $this->configurableType->getUsedProducts($product);
    }

    protected function getGroupedChildren(Product $product): array
    {
        $childrenData = [];
        $associatedProductsCollection = $this->groupedType->getAssociatedProductCollection($product)
        ->addAttributeToSelect('*') // Select all attributes
        ->load();

        
        foreach ($associatedProductsCollection as $childProduct) {
            $attributes = $childProduct->getData(); // Gets an array of all attributes
            // Or get a specific attribute:
            $name = $childProduct->getName();
            $customAttributeValue = $childProduct->getCustomAttributeCode(); // Replace with actual attribute code

            $childrenData[] = $childProduct;
        }

        return $childrenData;
    }

    public function getGroupedAttributes_bkp() {
        return [
            'Stock status' => ['ct_stock_status'],
            'Flange' => ['ct_nwkf','ct_icf','ct_vf','ct_vg'],
            'Electrical' => ['ct_the_number_of_electrode']
        ];
    }

    public function getGroupedAttributes()
    {
        $product = $this->getProduct();
        $isSimpleProduct = $this->isSimpleProduct($product);
        if (!$product || ($product->getTypeId() !== 'configurable' && $product->getTypeId() !== 'grouped' && !$isSimpleProduct)) {
            return [];
        }

        if ($isSimpleProduct) {
            $children[] = $product;     
        } else {
            $children = $this->getChildProducts($product);
            if (!$children || !count($children)) {
                return [];
            }
        }

        $attributeSetId = $product->getAttributeSetId();

        $groups = $this->_attributeGroupCollectionFactory
            ->create()
            ->setAttributeSetFilter($attributeSetId)
            ->setSortOrder()
            ->load();
            ////echo "<br />group sql:<br/>".$groups->getSelect()->__toString();

        $result = [];
        foreach ($groups as $group) {
            $groupName = $group->getAttributeGroupName();
            $attributes = $this->_attributeCollectionFactory
                ->create()
                ->setAttributeGroupFilter($group->getId())
                ->addVisibleFilter()
                ->addFieldToFilter('is_filterable', 1) // Filterable with results
                ->load();

            foreach ($attributes as $attribute) {
               $code = $attribute->getAttributeCode();
                $values = [];

                foreach ($children as $child) {
                    //echo '<pre>';
                  //  print_r($child->getData()); echo '</pre>';
                    $rawValue = $child->getData($code);

                    if ($rawValue === null || $rawValue === '') {
                        continue;
                    }

                    if ($attribute->usesSource()) {
                        $label = $attribute->getSource()->getOptionText($rawValue);
                        if ($label) {
                            $values[] = $label;
                        }
                    } else {
                        $values[] = $rawValue;
                    }
                }

                $values = array_unique(array_filter($values));

                // Only include attributes that actually have results
                if (!empty($values)) {
                    $result[$groupName][$code] = [
                        'label'  => $attribute->getFrontendLabel(),
                        'code'   => $code,
                        'values' => array_values($values)
                    ];
                }
            }
        }
        ///echo "<br /><pre style='clear:both'>"; print_r($result); echo "</pre>";
        return $result;
    }


    public function getChildAttributeLabel($product, $attributeCode)
    {
        $attribute = $product->getResource()->getAttribute($attributeCode);

        if ($attribute && $attribute->usesSource()) {
            return $attribute->getSource()->getOptionText(
                $product->getData($attributeCode)
            );
        }

        return $product->getData($attributeCode);
    }

    /**
     * Set tab title
     *
     * @return void
     */
    public function setTabTitle()
    {
        $title = $this->getCollectionSize()
            ? __('Models List %1', '<span class="counter">' . $this->getCollectionSize() . '</span>')
            : __('Models List');
        $this->setTitle($title);
    }

    /**
     * Get size of reviews collection
     *
     * @return int
     */
    public function getCollectionSize()
    {
        return count($this->getChildProducts());
    }
    
    public function getSkippedGroups()
    {
        return $this->_skipGroups;
    }
    
    public function getSkippedAttributes()
    {
        return $this->_skipAttributes;
    }

    public function isSimpleProduct(Product $product)
    {
        if ($product->getTypeId() == \Magento\Catalog\Model\Product\Type::TYPE_SIMPLE) {
            return true;
        }

        return false;
    }

    public function getParentProductUrl(Product $childProduct)
    {
        if (!$childProduct) {
            return null;
        }
        $parentIds = [];

        $parentIds = array_merge($parentIds,
            $this->configurableType->getParentIdsByChild($childProduct->getId())
        );

        $parentIds = array_merge($parentIds,
            $this->groupedType->getParentIdsByChild($childProduct->getId())
        );

        $parentIds = array_merge($parentIds,
            $this->bundleType->getParentIdsByChild($childProduct->getId())
        );

        if (!empty($parentIds)) {
            $parent = $this->productRepository->getById($parentIds[0]);
            return $parent->getProductUrl();
        }

        return null;
    }


}
