<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Block\Adminhtml\Product;

use Cosmotec\EccubeMigration\Plugin\AddAdditionalContentToProduct;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Cms\Model\Wysiwyg\Config as WysiwygConfig;
use Magento\Framework\Registry;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;

/**
 * Renders the "EC-CUBE Additional Content" product-edit section -
 * database-driven HTML tabs (Catalog, Assembly method, Pressing tools,
 * etc.), deliberately separate from EC-CUBE Specification since these are
 * variable-count HTML blocks, not discrete attribute values (see
 * ItemAdditionalContentImporter). Reads through
 * AddAdditionalContentToProduct's extension data, already resolved by
 * the product-repository plugin - no direct repository/DB access here.
 */
class AdditionalContent extends Template
{
    public function __construct(
        Context $context,
        private readonly Registry $registry,
        private readonly WysiwygConfig $wysiwygConfig,
        private readonly JsonSerializer $jsonSerializer,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Same Magento\Cms\Model\Wysiwyg\Config the native product Description/
     * Short Description WYSIWYG fields are ultimately built on top of - see
     * mage/adminhtml/wysiwyg/tiny_mce/setup.js, the lower-level class the
     * newer Magento_Ui/js/form/element/wysiwyg component itself wraps.
     * Variables/widgets are switched off (nothing in this module's scope
     * provides them, and the modal has no product/store context suited to
     * them); everything else (image browser, formatting toolbar, source
     * code editing) is the same config the rest of the admin uses.
     */
    public function getWysiwygConfigJson(): string
    {
        $config = $this->wysiwygConfig->getConfig([
            'add_variables' => false,
            'add_widgets' => false,
            'add_directives' => false,
            'tab_id' => 'cosmotec_eccube_tab_content',
        ]);

        return $this->jsonSerializer->serialize($config->getData());
    }

    /**
     * @return array<int, array{entity_id: int, source_row_id: ?int, tab_name_en: ?string, tab_name_ja: ?string, html_content: ?string, sort_no: int}>
     */
    public function getTabs(): array
    {
        $product = $this->registry->registry('current_product');

        if ($product === null) {
            return [];
        }

        $tabs = $product->getData(AddAdditionalContentToProduct::DATA_KEY);

        return is_array($tabs) ? $tabs : [];
    }

    public function getTabTitle(array $tab): string
    {
        $en = trim((string) ($tab['tab_name_en'] ?? ''));

        if ($en !== '') {
            return $en;
        }

        $ja = trim((string) ($tab['tab_name_ja'] ?? ''));

        return $ja !== '' ? $ja : (string) __('Untitled tab');
    }

    public function isEccubeImported(array $tab): bool
    {
        return ($tab['source_row_id'] ?? null) !== null;
    }

    public function getProductId(): int
    {
        $product = $this->registry->registry('current_product');

        return $product !== null ? (int) $product->getId() : 0;
    }

    public function getSaveUrl(): string
    {
        return $this->getUrl('cosmotec_eccube/product_additionalContent/save');
    }

    public function getDeleteUrl(): string
    {
        return $this->getUrl('cosmotec_eccube/product_additionalContent/delete');
    }
}
