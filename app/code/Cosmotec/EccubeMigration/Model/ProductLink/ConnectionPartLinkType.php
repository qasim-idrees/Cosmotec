<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\ProductLink;

/**
 * The custom Magento product-link type backing Connection Parts (Task 2).
 * Registered in catalog_product_link_type/catalog_product_link_attribute
 * by Setup\Patch\Data\CreateConnectionPartLinkType, and in
 * Magento\Catalog\Model\Product\LinkTypeProvider's linkTypes array via
 * etc/di.xml - mirrors Magento\Catalog\Model\Product\Link::LINK_TYPE_RELATED
 * / LINK_TYPE_UPSELL / LINK_TYPE_CROSSSELL (1/4/5), deliberately a
 * distinct id outside that range per docs/MIGRATION_ASSUMPTIONS.md §3
 * ("build as a distinct link type rather than overloading an existing
 * Magento link type").
 */
final class ConnectionPartLinkType
{
    public const LINK_TYPE_CONNECTION_PART = 20;
    public const LINK_TYPE_CODE = 'connection_part';
}
