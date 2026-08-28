<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\ProductLink;

use Magento\Catalog\Ui\DataProvider\Product\Related\AbstractDataProvider;

/**
 * Backs the "Add Connection Part" modal product-picker grid (Task 2) -
 * see view/adminhtml/ui_component/connection_part_product_listing.xml, a clone of
 * Magento's own related_product_listing.xml. getLinkType() returns the
 * same string used as the LinkTypeProvider array key/modifier DATA_SCOPE
 * ('connection_part'), unlike Magento's own Related/UpSell/CrossSell
 * providers (which return 'relation'/'up_sell'/'cross_sell' - the
 * catalog_product_link_type.code values - while ProductLinkInterface::
 * getLinkType() actually returns 'related'/'upsell'/'crosssell', so
 * AbstractDataProvider::addCollectionFilters()'s "exclude already-linked
 * products from the picker" comparison never matches in stock Magento).
 * Keeping both strings identical here means that filter genuinely works.
 */
class ConnectionPartDataProvider extends AbstractDataProvider
{
    protected function getLinkType(): string
    {
        return ConnectionPartLinkType::LINK_TYPE_CODE;
    }
}
