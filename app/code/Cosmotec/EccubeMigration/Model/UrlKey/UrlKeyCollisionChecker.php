<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\UrlKey;

use Magento\CatalogUrlRewrite\Model\ProductUrlPathGenerator;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\UrlRewrite\Model\UrlFinderInterface;
use Magento\UrlRewrite\Service\V1\Data\UrlRewrite;

/**
 * Checks whether a candidate url_key would collide with an EXISTING
 * Magento url_rewrite - the real uniqueness Magento itself enforces
 * (request_path + store_id, confirmed by reading module-url-rewrite's own
 * db_schema.xml unique constraint), not a naive "does this exact url_key
 * value exist elsewhere" comparison.
 *
 * Uses Magento's own official UrlFinderInterface::findOneByData() (its
 * concrete implementation, Storage\DbStorage, queries the database
 * directly with no caching layer involved) rather than raw SQL, per the
 * explicit instruction to use Magento's actual URL rewrite implementation.
 *
 * This is what correctly avoids false-positive collisions with
 * categories without any special-casing: a non-top-level category's real
 * request_path includes its full ancestor slug chain (e.g.
 * "others/widget.html"), which never matches a flat product/item request
 * path ("widget.html") even when their bare url_key values happen to be
 * textually identical - live-confirmed this round (22 apparent
 * bare-url_key matches across categories/items/products, 0 real
 * request_path collisions once checked correctly). A genuine top-level
 * category collision (flat request_path, no ancestor prefix) would still
 * be caught correctly, with no extra logic needed.
 *
 * Scoped to catalog/seo/product_url_suffix only - Items and Simple
 * Products are the same Magento entity type (catalog_product) sharing
 * the same request-path namespace, so this single check correctly covers
 * both without needing to know which one is being checked.
 */
class UrlKeyCollisionChecker
{
    public function __construct(
        private readonly UrlFinderInterface $urlFinder,
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function wouldCollide(string $urlKey): bool
    {
        if ($urlKey === '') {
            return false;
        }

        foreach ($this->storeManager->getStores() as $store) {
            $suffix = (string) $this->scopeConfig->getValue(
                ProductUrlPathGenerator::XML_PATH_PRODUCT_URL_SUFFIX,
                ScopeInterface::SCOPE_STORE,
                $store->getId()
            );

            $existing = $this->urlFinder->findOneByData([
                UrlRewrite::REQUEST_PATH => $urlKey . $suffix,
                UrlRewrite::STORE_ID => $store->getId(),
            ]);

            if ($existing !== null) {
                return true;
            }
        }

        return false;
    }
}
