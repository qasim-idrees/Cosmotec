<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\UrlKey;

/**
 * Deterministic fallback for the one case Magento's own native url_key
 * generation (Product::formatUrlKey() / Category::formatUrlKey()) cannot
 * handle on its own: a name that transliterates to '' entirely (e.g.
 * Japanese-only text - live-confirmed against 2 real categories and 8
 * real products in this dataset). Category's own save observer
 * (CategoryUrlPathAutogeneratorObserver) throws a LocalizedException in
 * this case rather than silently accepting an empty key; Product's
 * observer silently leaves url_key unset. Both are worse outcomes than a
 * stable, deterministic fallback.
 *
 * This is NOT a new invention - it mirrors the exact pattern Magento's
 * own core code already uses for the identical failure mode:
 * Magento\Eav\Model\Entity\Attribute\Group::beforeSave() falls back to
 * md5(strtolower($name)) when its own translit-based code generation
 * produces an empty or reserved result. This class does the equivalent
 * for url_key instead of attribute_group_code.
 *
 * Deliberately a pure function of its two string inputs - no SKU, no
 * other source-system data, and never the raw EC-CUBE id exposed
 * unhashed in the URL. Two distinct inputs are hashed by different
 * callers depending on which failure mode is being resolved:
 *  - CategoryImporter/ItemImporter/ProductImporter pass the entity NAME
 *    when it transliterates to '' entirely (Japanese-only text).
 *  - ItemImporter/ProductImporter also pass 'item:{eccubeItemId}' /
 *    'product:{eccubeProductId}' (never the bare name) when the native,
 *    name-derived url_key would collide with an existing url_rewrite -
 *    hashing the name here would collide identically for every record
 *    that already shares that name, which is the exact problem being
 *    solved, so the EC-CUBE id is used as the hash input instead while
 *    staying out of the resulting URL itself.
 *
 * Deliberately has no injected dependencies and does no dataset-wide
 * scanning or collision map building, unlike the old resolvers - actual
 * collision detection is delegated to UrlKeyCollisionChecker (which
 * queries Magento's real url_rewrite state), with Magento's own
 * save-time uniqueness enforcement (UrlAlreadyExistsException for
 * products, CouldNotSaveException for categories - both live-confirmed
 * this session) as the final backstop, consistent with "prefer
 * Magento's own mechanism."
 *
 * Only ever consulted at entity creation (see CategoryImporter/
 * ItemImporter/ProductImporter), never on update, so an already-created
 * entity's url_key - fallback-generated or native - never changes again
 * regardless of later name edits.
 */
class UrlKeyFallbackGenerator
{
    private const HASH_LENGTH = 12;

    public function generate(string $prefix, string $name): string
    {
        return $prefix . '-' . substr(hash('sha256', $name), 0, self::HASH_LENGTH);
    }
}
