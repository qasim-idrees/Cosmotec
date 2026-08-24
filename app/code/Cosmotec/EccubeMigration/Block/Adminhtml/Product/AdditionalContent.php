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
use Magento\Framework\Registry;

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
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @return array<int, array{source_row_id: int, tab_name_en: ?string, tab_name_ja: ?string, html_content: ?string, sort_no: int}>
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
}
