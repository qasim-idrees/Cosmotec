<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\UrlKey;

use Cosmotec\EccubeMigration\Api\CategoryRepositoryInterface;

/**
 * Deterministic, source-driven url_key assignment for every EC-CUBE
 * category - the category counterpart to UrlKeyResolver (items/products),
 * but deliberately NOT the same collision scope.
 *
 * Inspected Magento's actual category url-key/rewrite behavior before
 * building this (per the explicit instruction not to assume product
 * behavior is identical) rather than guessing:
 *   - Magento\Catalog\Model\ResourceModel\Category has no leaf-level
 *     url_key uniqueness check at all - confirmed by reading the resource
 *     model source. A live audit of the current dataset found 18 sets of
 *     categories sharing the identical bare url_key value with zero save
 *     errors, in every case because they sit under different parents.
 *   - The only real uniqueness constraint categories are subject to is
 *     url_rewrite's own unique (request_path, store_id) index, and a
 *     category's request_path is the FULL ancestor chain of url_keys, not
 *     the leaf value alone - so two categories only ever actually collide
 *     if they share both the same parent AND the same computed slug.
 *
 * This means the correct collision scope for categories is per sibling
 * group (categories sharing an EC-CUBE parent_category_id), not the
 * entire flat dataset the way item/product url_keys must be (products and
 * items have no hierarchy segmenting their shared request-path
 * namespace). Grouping by parent also matches the actual data: EC-CUBE
 * top-level categories (parent_category_id === null) are siblings of each
 * other under the store's root category in Magento, so they share one
 * collision group too (sentinel key 0).
 *
 * Same non-negotiable rules as UrlKeyResolver: computed against the FULL
 * EC-CUBE category dataset (not the current Magento catalog) for
 * fresh-install determinism; disambiguation suffix is always the
 * category's own stable EC-CUBE id, never a sequential counter; a
 * Japanese-only/symbol-only name that slugifies to '' falls back to
 * "category-{id}". Computed once per process and cached, matching the
 * ~324-row scale (cheap to hold in full, unlike the 71,072-row media
 * table this project's performance rules otherwise guard against).
 *
 * Deliberately says nothing about WHEN a caller may use this - see
 * CategoryImporter for why it is only ever called at initial creation,
 * never on update, so an already-imported category's url_key stays
 * stable across a later EC-CUBE name edit.
 */
class CategoryUrlKeyResolver
{
    private const ROOT_GROUP_KEY = 0;

    /** @var array<int, string>|null */
    private ?array $urlKeys = null;

    public function __construct(
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly UrlKeySlugifier $slugifier
    ) {
    }

    public function resolveForCategory(int $categoryId): string
    {
        $this->buildIfNeeded();

        return $this->urlKeys[$categoryId] ?? $this->fallback($categoryId);
    }

    private function buildIfNeeded(): void
    {
        if ($this->urlKeys !== null) {
            return;
        }

        $groups = [];
        $offset = 0;
        $batchSize = 500;

        while (true) {
            $page = $this->categoryRepository->getBatch($offset, $batchSize);

            if ($page === []) {
                break;
            }

            foreach ($page as $category) {
                $groupKey = $category->getParentCategoryId() ?? self::ROOT_GROUP_KEY;
                $groups[$groupKey][$this->baseValue($category->getId(), $category->getCategoryNameEn())][] = $category->getId();
            }

            if (count($page) < $batchSize) {
                break;
            }

            $offset += $batchSize;
        }

        $resolved = [];

        foreach ($groups as $siblingsByBase) {
            foreach ($siblingsByBase as $base => $ids) {
                if (count($ids) === 1) {
                    $resolved[$ids[0]] = $base;

                    continue;
                }

                foreach ($ids as $id) {
                    $resolved[$id] = $base . '-' . $id;
                }
            }
        }

        $this->urlKeys = $resolved;
    }

    private function baseValue(int $id, string $name): string
    {
        $slug = $this->slugifier->slugify($name);

        return $slug !== '' ? $slug : $this->fallback($id);
    }

    private function fallback(int $id): string
    {
        return sprintf('category-%d', $id);
    }
}
