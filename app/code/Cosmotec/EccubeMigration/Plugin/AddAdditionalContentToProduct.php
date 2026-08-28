<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Plugin;

use Cosmotec\EccubeMigration\Api\ItemAdditionalContentProviderInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;

/**
 * Surfaces EC-CUBE Additional Content tabs (dtb_item_additional_information)
 * on the Grouped Product so blocks, templates, the admin UI section and
 * API consumers can read them from the product they already have - same
 * pattern as AddProductReferencesToProduct/AddConnectionPartsToProduct.
 */
class AddAdditionalContentToProduct
{
    public const DATA_KEY = 'eccube_additional_content';

    public function __construct(
        private readonly ItemAdditionalContentProviderInterface $contentProvider
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

        $tabs = [];

        foreach ($this->contentProvider->getByMagentoProductId($productId) as $map) {
            $sourceRowId = $map->getEccubeAdditionalInformationId();

            $tabs[] = [
                'entity_id' => (int) $map->getId(),
                // Never coerced to (int) - a NULL here (admin-created tab,
                // no EC-CUBE origin) must stay NULL so
                // Block\Adminhtml\Product\AdditionalContent::isEccubeImported()
                // can tell it apart from a real source row id of 0.
                'source_row_id' => $sourceRowId !== null ? (int) $sourceRowId : null,
                'tab_name_en' => $map->getTabNameEn(),
                'tab_name_ja' => $map->getTabNameJa(),
                'html_content' => $map->getHtmlContent(),
                'sort_no' => (int) $map->getSortNo(),
            ];
        }

        $product->setData(self::DATA_KEY, $tabs);

        return $product;
    }
}
