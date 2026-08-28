<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Ui\DataProvider\Product\Form\Modifier;

use Cosmotec\EccubeMigration\Model\ProductLink\ConnectionPartLinkType;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\Data\ProductLinkInterface;
use Magento\Catalog\Api\ProductLinkRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Model\Locator\LocatorInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Ui\DataProvider\Product\Form\Modifier\AbstractModifier;
use Magento\Eav\Api\AttributeSetRepositoryInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Phrase;
use Magento\Framework\UrlInterface;
use Magento\Ui\Component\DynamicRows;
use Magento\Ui\Component\Form\Element\DataType\Number;
use Magento\Ui\Component\Form\Element\DataType\Text;
use Magento\Ui\Component\Form\Element\Input;
use Magento\Ui\Component\Form\Field;
use Magento\Ui\Component\Form\Fieldset;
use Magento\Ui\Component\Modal;

/**
 * Task 2 QA rework: Connection Parts, administrable exactly like Related
 * Products / Up-Sells / Cross-Sells (a listing of linked products with a
 * per-row Remove action, an "Add Connection Part(s)" button opening a
 * modal with Magento's own product-search grid, multi-select, and native
 * persistence through the standard product-link save path).
 *
 * A deliberate one-fieldset simplification of Magento's own
 * \Magento\Catalog\Ui\DataProvider\Product\Form\Modifier\Related, which
 * builds three near-identical fieldsets (related/upsell/crosssell) off
 * the same structure - see that class for the full explanation of every
 * piece reused here verbatim (getButtonSet/getGenericModal/getGrid/
 * fillMeta). The one substantive difference: Related's per-type
 * "Product Attribute Media Media Gallery Entry" price-column re-formatting
 * (getPriceModifier) is kept for consistency with the picker grid columns,
 * even though Connection Parts does not need pricing logic of its own.
 */
class ConnectionParts extends AbstractModifier
{
    private const DATA_SCOPE = 'connection_part';
    private const GROUP_CONNECTION_PARTS = 'connection_parts';
    private const PREVIOUS_GROUP = 'related';
    private const SORT_ORDER = 115;

    private ?\Magento\Catalog\Ui\Component\Listing\Columns\Price $priceModifier = null;

    public function __construct(
        private readonly LocatorInterface $locator,
        private readonly UrlInterface $urlBuilder,
        private readonly ProductLinkRepositoryInterface $productLinkRepository,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ImageHelper $imageHelper,
        private readonly Status $status,
        private readonly AttributeSetRepositoryInterface $attributeSetRepository,
        private readonly string $scopeName = '',
        private readonly string $scopePrefix = ''
    ) {
    }

    public function modifyMeta(array $meta): array
    {
        return array_replace_recursive($meta, [
            self::GROUP_CONNECTION_PARTS => [
                'children' => [
                    $this->scopePrefix . self::DATA_SCOPE => $this->getConnectionPartFieldset(),
                ],
                'arguments' => [
                    'data' => [
                        'config' => [
                            'label' => __('EC-CUBE Connection Parts'),
                            'collapsible' => true,
                            'componentType' => Fieldset::NAME,
                            'dataScope' => '',
                            'sortOrder' => $this->getNextGroupSortOrder($meta, self::PREVIOUS_GROUP, self::SORT_ORDER),
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function modifyData(array $data): array
    {
        /** @var \Magento\Catalog\Model\Product $product */
        $product = $this->locator->getProduct();
        $productId = $product->getId();

        if (!$productId) {
            return $data;
        }

        $priceModifier = $this->getPriceModifier();
        $priceModifier->setData('name', 'price');

        $data[$productId]['links'][self::DATA_SCOPE] = [];
        $linkItems = [];

        foreach ($this->productLinkRepository->getList($product) as $linkItem) {
            if ($linkItem->getLinkType() !== self::DATA_SCOPE) {
                continue;
            }

            $linkItems[] = $linkItem;
        }

        usort($linkItems, static fn ($a, $b) => $a->getPosition() <=> $b->getPosition());

        foreach ($linkItems as $linkItem) {
            $linkedProduct = $this->productRepository->get(
                $linkItem->getLinkedProductSku(),
                false,
                $this->locator->getStore()->getId()
            );
            $data[$productId]['links'][self::DATA_SCOPE][] = $this->fillData($linkedProduct, $linkItem);
        }

        if ($data[$productId]['links'][self::DATA_SCOPE] !== []) {
            $dataMap = $priceModifier->prepareDataSource([
                'data' => ['items' => $data[$productId]['links'][self::DATA_SCOPE]],
            ]);
            $data[$productId]['links'][self::DATA_SCOPE] = $dataMap['data']['items'];
        }

        $data[$productId][self::DATA_SOURCE_DEFAULT]['current_product_id'] = $productId;
        $data[$productId][self::DATA_SOURCE_DEFAULT]['current_store_id'] = $this->locator->getStore()->getId();

        return $data;
    }

    private function getPriceModifier(): \Magento\Catalog\Ui\Component\Listing\Columns\Price
    {
        if ($this->priceModifier === null) {
            $this->priceModifier = ObjectManager::getInstance()->get(
                \Magento\Catalog\Ui\Component\Listing\Columns\Price::class
            );
        }

        return $this->priceModifier;
    }

    private function fillData(ProductInterface $linkedProduct, ProductLinkInterface $linkItem): array
    {
        return [
            'id' => $linkedProduct->getId(),
            'thumbnail' => $this->imageHelper->init($linkedProduct, 'product_listing_thumbnail')->getUrl(),
            'name' => $linkedProduct->getName(),
            'status' => $this->status->getOptionText($linkedProduct->getStatus()),
            'attribute_set' => $this->attributeSetRepository
                ->get($linkedProduct->getAttributeSetId())
                ->getAttributeSetName(),
            'sku' => $linkItem->getLinkedProductSku(),
            'price' => $linkedProduct->getPrice(),
            'position' => $linkItem->getPosition(),
        ];
    }

    private function getConnectionPartFieldset(): array
    {
        $scope = $this->scopePrefix . self::DATA_SCOPE;

        return [
            'children' => [
                'button_set' => $this->getButtonSet(
                    __('EC-CUBE Connection Parts are accessories/parts connected to this product, distinct from Related Products.'),
                    __('Add Connection Part'),
                    $scope
                ),
                'modal' => $this->getGenericModal(__('Add Connection Part'), $scope),
                self::DATA_SCOPE => $this->getGrid($scope),
            ],
            'arguments' => [
                'data' => [
                    'config' => [
                        'additionalClasses' => 'admin__fieldset-section',
                        'label' => __('Connection Parts'),
                        'collapsible' => false,
                        'componentType' => Fieldset::NAME,
                        'dataScope' => '',
                        'sortOrder' => 10,
                    ],
                ],
            ],
        ];
    }

    private function getButtonSet(Phrase $content, Phrase $buttonTitle, string $scope): array
    {
        $modalTarget = $this->scopeName . '.' . self::GROUP_CONNECTION_PARTS . '.' . $scope . '.modal';

        return [
            'arguments' => [
                'data' => [
                    'config' => [
                        'formElement' => 'container',
                        'componentType' => 'container',
                        'label' => false,
                        'content' => $content,
                        'template' => 'ui/form/components/complex',
                    ],
                ],
            ],
            'children' => [
                'button_' . $scope => [
                    'arguments' => [
                        'data' => [
                            'config' => [
                                'formElement' => 'container',
                                'componentType' => 'container',
                                'component' => 'Magento_Ui/js/form/components/button',
                                'actions' => [
                                    ['targetName' => $modalTarget, 'actionName' => 'toggleModal'],
                                    ['targetName' => $modalTarget . '.' . $scope . '_product_listing', 'actionName' => 'render'],
                                ],
                                'title' => $buttonTitle,
                                'provider' => null,
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function getGenericModal(Phrase $title, string $scope): array
    {
        $listingTarget = $scope . '_product_listing';

        return [
            'arguments' => [
                'data' => [
                    'config' => [
                        'componentType' => Modal::NAME,
                        'dataScope' => '',
                        'options' => [
                            'title' => $title,
                            'buttons' => [
                                ['text' => __('Cancel'), 'actions' => ['closeModal']],
                                [
                                    'text' => __('Add Selected Products'),
                                    'class' => 'action-primary',
                                    'actions' => [
                                        ['targetName' => 'index = ' . $listingTarget, 'actionName' => 'save'],
                                        'closeModal',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'children' => [
                $listingTarget => [
                    'arguments' => [
                        'data' => [
                            'config' => [
                                'autoRender' => false,
                                'componentType' => 'insertListing',
                                'dataScope' => $listingTarget,
                                'externalProvider' => $listingTarget . '.' . $listingTarget . '_data_source',
                                'selectionsProvider' => $listingTarget . '.' . $listingTarget . '.product_columns.ids',
                                'ns' => $listingTarget,
                                'render_url' => $this->urlBuilder->getUrl('mui/index/render'),
                                'realTimeLink' => true,
                                'dataLinks' => ['imports' => false, 'exports' => true],
                                'behaviourType' => 'simple',
                                'externalFilterMode' => true,
                                'imports' => [
                                    'productId' => '${ $.provider }:data.product.current_product_id',
                                    'storeId' => '${ $.provider }:data.product.current_store_id',
                                    '__disableTmpl' => ['productId' => false, 'storeId' => false],
                                ],
                                'exports' => [
                                    'productId' => '${ $.externalProvider }:params.current_product_id',
                                    'storeId' => '${ $.externalProvider }:params.current_store_id',
                                    '__disableTmpl' => ['productId' => false, 'storeId' => false],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function getGrid(string $scope): array
    {
        $dataProvider = $scope . '_product_listing';

        return [
            'arguments' => [
                'data' => [
                    'config' => [
                        'additionalClasses' => 'admin__field-wide',
                        'componentType' => DynamicRows::NAME,
                        'label' => null,
                        'columnsHeader' => false,
                        'columnsHeaderAfterRender' => true,
                        'renderDefaultRecord' => false,
                        'template' => 'ui/dynamic-rows/templates/grid',
                        'component' => 'Magento_Catalog/js/components/reset-dynamic-rows-grid-row-position-on-delete',
                        'addButton' => false,
                        'recordTemplate' => 'record',
                        'dataScope' => 'data.links',
                        'deleteButtonLabel' => __('Remove'),
                        'dataProvider' => $dataProvider,
                        'map' => [
                            'id' => 'entity_id',
                            'name' => 'name',
                            'status' => 'status_text',
                            'attribute_set' => 'attribute_set_text',
                            'sku' => 'sku',
                            'price' => 'price',
                            'thumbnail' => 'thumbnail_src',
                        ],
                        'links' => [
                            'insertData' => '${ $.provider }:${ $.dataProvider }',
                            '__disableTmpl' => ['insertData' => false],
                        ],
                        'sortOrder' => 2,
                    ],
                ],
            ],
            'children' => [
                'record' => [
                    'arguments' => [
                        'data' => [
                            'config' => [
                                'componentType' => 'container',
                                'isTemplate' => true,
                                'is_collection' => true,
                                'component' => 'Magento_Ui/js/dynamic-rows/record',
                                'dataScope' => '',
                            ],
                        ],
                    ],
                    'children' => $this->fillMeta(),
                ],
            ],
        ];
    }

    private function fillMeta(): array
    {
        return [
            'id' => $this->getTextColumn('id', false, __('ID'), 0),
            'thumbnail' => [
                'arguments' => [
                    'data' => [
                        'config' => [
                            'componentType' => Field::NAME,
                            'formElement' => Input::NAME,
                            'elementTmpl' => 'ui/dynamic-rows/cells/thumbnail',
                            'dataType' => Text::NAME,
                            'dataScope' => 'thumbnail',
                            'fit' => true,
                            'label' => __('Thumbnail'),
                            'sortOrder' => 10,
                        ],
                    ],
                ],
            ],
            'name' => $this->getTextColumn('name', false, __('Name'), 20),
            'status' => $this->getTextColumn('status', true, __('Status'), 30),
            'attribute_set' => $this->getTextColumn('attribute_set', false, __('Attribute Set'), 40),
            'sku' => $this->getTextColumn('sku', true, __('SKU'), 50),
            'price' => $this->getTextColumn('price', true, __('Price'), 60),
            'actionDelete' => [
                'arguments' => [
                    'data' => [
                        'config' => [
                            'additionalClasses' => 'data-grid-actions-cell',
                            'componentType' => 'actionDelete',
                            'dataType' => Text::NAME,
                            'label' => __('Actions'),
                            'sortOrder' => 70,
                            'fit' => true,
                        ],
                    ],
                ],
            ],
            'position' => [
                'arguments' => [
                    'data' => [
                        'config' => [
                            'dataType' => Number::NAME,
                            'formElement' => Input::NAME,
                            'componentType' => Field::NAME,
                            'dataScope' => 'position',
                            'sortOrder' => 80,
                            'visible' => false,
                        ],
                    ],
                ],
            ],
        ];
    }

    private function getTextColumn(string $dataScope, bool $fit, Phrase $label, int $sortOrder): array
    {
        return [
            'arguments' => [
                'data' => [
                    'config' => [
                        'componentType' => Field::NAME,
                        'formElement' => Input::NAME,
                        'elementTmpl' => 'ui/dynamic-rows/cells/text',
                        'component' => 'Magento_Ui/js/form/element/text',
                        'dataType' => Text::NAME,
                        'dataScope' => $dataScope,
                        'fit' => $fit,
                        'label' => $label,
                        'sortOrder' => $sortOrder,
                    ],
                ],
            ],
        ];
    }
}
