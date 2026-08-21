<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\UrlKey;

use Cosmotec\EccubeMigration\Api\ItemRepositoryInterface;
use Cosmotec\EccubeMigration\Api\ProductRepositoryInterface;

/**
 * Deterministic, source-driven url_key assignment for every EC-CUBE item
 * (Grouped Product) and product (Simple Product) - see BUILD_STATUS.md for
 * the full analysis this implements.
 *
 * Algorithm (collision handling against the FULL migration source, not the
 * current Magento catalog, and not import order):
 *   1. Slugify name_en (UrlKeySlugifier - no Magento dependency, so this
 *      never depends on a target store's transliteration config).
 *   2. If that yields '' (Japanese-only names, symbol-only names, etc.),
 *      fall back to "item-{id}"/"product-{id}" - the same convention
 *      ProductMapper::resolveName() already uses for empty names.
 *   3. Group EVERY item and product (combined - see below) by that base
 *      value. A group of 1 keeps the clean base value untouched. A group
 *      of 2+ gets "{base}-{id}" on every member, using each entity's own
 *      stable EC-CUBE primary key as the suffix - never a sequential
 *      counter, which would depend on processing order.
 *   4. Defensive second pass: an item and a product could theoretically
 *      still collide after step 3 if their EC-CUBE ids happen to be the
 *      same number AND their computed value from step 3 is identical
 *      (only possible if both needed the id suffix and share that exact
 *      id) - resolved with a further "{base}-{type}-{id}" compound,
 *      guaranteed unique since (type, id) pairs are unique by
 *      construction. Not observed in this project's live data, but the
 *      guarantee must hold in general, not just for the current dataset.
 *
 * Items and products are grouped TOGETHER, not separately: Magento's
 * url_rewrite request_path uniqueness is enforced per store (confirmed
 * live this session via DbStorage::checkDuplicates()), and both Grouped
 * and Simple Products share that same store-scoped path space - an item
 * and a product with the same computed slug would collide on the
 * storefront exactly as two products would.
 *
 * Computed once per process (all ~1,092 items + ~27,590 products - a
 * single bulk read each, not the 71,072-row scale this project's
 * performance rules otherwise guard against) and cached for the lifetime
 * of this instance, which is shared across an entire CLI run.
 *
 * Deliberately says nothing about WHEN a caller may use this - see
 * ItemImporter/ProductImporter for why it is only ever called at initial
 * creation (never on update), which is what makes an already-imported
 * product's url_key stable across later EC-CUBE name edits.
 */
class UrlKeyResolver
{
    private const TYPE_ITEM = 'item';
    private const TYPE_PRODUCT = 'product';

    /** @var array<int, string>|null */
    private ?array $itemUrlKeys = null;

    /** @var array<int, string>|null */
    private ?array $productUrlKeys = null;

    public function __construct(
        private readonly ItemRepositoryInterface $itemRepository,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly UrlKeySlugifier $slugifier
    ) {
    }

    public function resolveForItem(int $itemId): string
    {
        $this->buildIfNeeded();

        return $this->itemUrlKeys[$itemId] ?? $this->fallback(self::TYPE_ITEM, $itemId);
    }

    public function resolveForProduct(int $productId): string
    {
        $this->buildIfNeeded();

        return $this->productUrlKeys[$productId] ?? $this->fallback(self::TYPE_PRODUCT, $productId);
    }

    private function buildIfNeeded(): void
    {
        if ($this->itemUrlKeys !== null) {
            return;
        }

        $entries = [];

        foreach ($this->itemRepository->getAllIdsAndNames() as $id => $name) {
            $entries[] = ['type' => self::TYPE_ITEM, 'id' => $id, 'base' => $this->baseValue(self::TYPE_ITEM, $id, $name)];
        }

        foreach ($this->productRepository->getAllIdsAndNames() as $id => $name) {
            $entries[] = ['type' => self::TYPE_PRODUCT, 'id' => $id, 'base' => $this->baseValue(self::TYPE_PRODUCT, $id, $name)];
        }

        $groups = [];

        foreach ($entries as $entry) {
            $groups[$entry['base']][] = $entry;
        }

        $resolved = [];

        foreach ($groups as $base => $members) {
            if (count($members) === 1) {
                $resolved[] = ['type' => $members[0]['type'], 'id' => $members[0]['id'], 'final' => $base];

                continue;
            }

            foreach ($members as $member) {
                $resolved[] = ['type' => $member['type'], 'id' => $member['id'], 'final' => $base . '-' . $member['id']];
            }
        }

        // Defensive second pass - see class docblock. Only reached if two
        // entries produced the identical "{base}-{id}" string, which
        // requires both a shared base AND a shared numeric id across an
        // item and a product.
        $finalGroups = [];

        foreach ($resolved as $index => $entry) {
            $finalGroups[$entry['final']][] = $index;
        }

        foreach ($finalGroups as $final => $indexes) {
            if (count($indexes) === 1) {
                continue;
            }

            foreach ($indexes as $index) {
                $entry = $resolved[$index];
                $resolved[$index]['final'] = $final . '-' . $entry['type'];
            }
        }

        $itemUrlKeys = [];
        $productUrlKeys = [];

        foreach ($resolved as $entry) {
            if ($entry['type'] === self::TYPE_ITEM) {
                $itemUrlKeys[$entry['id']] = $entry['final'];
            } else {
                $productUrlKeys[$entry['id']] = $entry['final'];
            }
        }

        $this->itemUrlKeys = $itemUrlKeys;
        $this->productUrlKeys = $productUrlKeys;
    }

    private function baseValue(string $type, int $id, string $name): string
    {
        $slug = $this->slugifier->slugify($name);

        return $slug !== '' ? $slug : $this->fallback($type, $id);
    }

    private function fallback(string $type, int $id): string
    {
        return sprintf('%s-%d', $type, $id);
    }
}
