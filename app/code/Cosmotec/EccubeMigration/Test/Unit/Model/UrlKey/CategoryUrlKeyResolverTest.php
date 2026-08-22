<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Test\Unit\Model\UrlKey;

use Cosmotec\EccubeMigration\Api\CategoryRepositoryInterface;
use Cosmotec\EccubeMigration\Api\Data\CategoryInterface;
use Cosmotec\EccubeMigration\Model\UrlKey\CategoryUrlKeyResolver;
use Cosmotec\EccubeMigration\Model\UrlKey\UrlKeySlugifier;
use PHPUnit\Framework\TestCase;

class CategoryUrlKeyResolverTest extends TestCase
{
    public function testDistinctNamesUnderSameParentKeepCleanSlugs(): void
    {
        $resolver = $this->makeResolver([
            $this->cat(1, null, 'Feedthrough'),
            $this->cat(2, 1, 'Coaxial'),
            $this->cat(3, 1, 'Ceramic'),
        ]);

        $this->assertSame('coaxial', $resolver->resolveForCategory(2));
        $this->assertSame('ceramic', $resolver->resolveForCategory(3));
    }

    public function testGenuineSiblingCollisionGetsIdSuffixed(): void
    {
        // Two DIFFERENT categories under the SAME parent that both slugify
        // to "nipple" - the real collision case Magento would actually
        // reject when saving the second one.
        $resolver = $this->makeResolver([
            $this->cat(1, null, 'Root'),
            $this->cat(10, 1, 'Nipple'),
            $this->cat(11, 1, 'Nipple'),
        ]);

        $this->assertSame('nipple-10', $resolver->resolveForCategory(10));
        $this->assertSame('nipple-11', $resolver->resolveForCategory(11));
    }

    public function testSameSlugUnderDifferentParentsIsNotSuffixed(): void
    {
        // Live-observed pattern in the real dataset: many "Nipple"
        // sub-categories exist, one per parent family. Magento only
        // enforces uniqueness on the full computed request path (built
        // from the whole ancestor chain), never the bare leaf url_key, so
        // these must NOT be suffixed - suffixing them would needlessly
        // change already-correct, already-unique URLs.
        $resolver = $this->makeResolver([
            $this->cat(1, null, 'Root'),
            $this->cat(20, 1, 'Family A'),
            $this->cat(21, 1, 'Family B'),
            $this->cat(30, 20, 'Nipple'),
            $this->cat(31, 21, 'Nipple'),
        ]);

        $this->assertSame('nipple', $resolver->resolveForCategory(30));
        $this->assertSame('nipple', $resolver->resolveForCategory(31));
    }

    public function testTopLevelCategoriesShareOneCollisionGroup(): void
    {
        // parent_category_id === null (top-level) categories are siblings
        // of each other under the store root in Magento, so a name clash
        // between two top-level categories must be suffixed exactly like
        // any other sibling collision.
        $resolver = $this->makeResolver([
            $this->cat(1, null, 'Sale'),
            $this->cat(2, null, 'Sale'),
        ]);

        $this->assertSame('sale-1', $resolver->resolveForCategory(1));
        $this->assertSame('sale-2', $resolver->resolveForCategory(2));
    }

    public function testJapaneseOnlyNameFallsBackToDeterministicCategoryId(): void
    {
        $resolver = $this->makeResolver([
            $this->cat(1, null, '日本語のみ'),
        ]);

        $this->assertSame('category-1', $resolver->resolveForCategory(1));
    }

    public function testUnknownCategoryIdFallsBackWithoutBuildingTwice(): void
    {
        $resolver = $this->makeResolver([
            $this->cat(1, null, 'Root'),
        ]);

        $this->assertSame('category-999', $resolver->resolveForCategory(999));
        // A second call for a real id must still resolve correctly - the
        // dataset is built once and cached, not corrupted by a miss.
        $this->assertSame('root', $resolver->resolveForCategory(1));
    }

    public function testResultIsDeterministicAcrossFreshInstances(): void
    {
        $categories = [
            $this->cat(1, null, 'Root'),
            $this->cat(10, 1, 'Nipple'),
            $this->cat(11, 1, 'Nipple'),
        ];

        $first = $this->makeResolver($categories)->resolveForCategory(11);
        $second = $this->makeResolver($categories)->resolveForCategory(11);

        $this->assertSame($first, $second);
        $this->assertSame('nipple-11', $first);
    }

    /**
     * @param CategoryInterface[] $categories
     */
    private function makeResolver(array $categories): CategoryUrlKeyResolver
    {
        $repository = $this->createMock(CategoryRepositoryInterface::class);
        $repository->method('getBatch')->willReturnCallback(
            static fn (int $offset, int $limit): array => $offset === 0 ? $categories : []
        );

        return new CategoryUrlKeyResolver($repository, new UrlKeySlugifier());
    }

    private function cat(int $id, ?int $parentId, string $nameEn): CategoryInterface
    {
        $category = $this->createMock(CategoryInterface::class);
        $category->method('getId')->willReturn($id);
        $category->method('getParentCategoryId')->willReturn($parentId);
        $category->method('getCategoryNameEn')->willReturn($nameEn);

        return $category;
    }
}
