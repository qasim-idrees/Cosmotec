<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Test\Unit\Model\Mapper;

use Cosmotec\EccubeMigration\Api\CategoryMapRepositoryInterface;
use Cosmotec\EccubeMigration\Api\Data\CategoryInterface;
use Cosmotec\EccubeMigration\Api\EccubeConfigProviderInterface;
use Cosmotec\EccubeMigration\Model\CategoryMap;
use Cosmotec\EccubeMigration\Model\Mapper\CategoryMapper;
use Cosmotec\EccubeMigration\Model\Mapper\Exception\UnresolvedParentException;
use Cosmotec\EccubeMigration\Model\UrlKey\CategoryUrlKeyResolver;
use PHPUnit\Framework\TestCase;

class CategoryMapperTest extends TestCase
{
    private EccubeConfigProviderInterface $config;
    private CategoryMapRepositoryInterface $categoryMapRepository;
    private CategoryUrlKeyResolver $urlKeyResolver;
    private CategoryMapper $mapper;

    protected function setUp(): void
    {
        $this->config = $this->createMock(EccubeConfigProviderInterface::class);
        $this->categoryMapRepository = $this->createMock(CategoryMapRepositoryInterface::class);
        $this->urlKeyResolver = $this->createMock(CategoryUrlKeyResolver::class);
        $this->mapper = new CategoryMapper($this->config, $this->categoryMapRepository, $this->urlKeyResolver);
    }

    public function testTopLevelCategoryUsesConfiguredRootCategory(): void
    {
        $this->config->method('getMagentoRootCategoryId')->willReturn(2);
        $category = $this->makeCategory(id: 1, parentId: null, name: 'Shoes');

        $mapped = $this->mapper->map($category);

        $this->assertSame(2, $mapped->getMagentoParentId());
    }

    public function testChildCategoryUsesAlreadyImportedParentsMagentoId(): void
    {
        $category = $this->makeCategory(id: 5, parentId: 1, name: 'Sneakers');
        $parentMap = $this->getMockBuilder(CategoryMap::class)
            ->disableOriginalConstructor()
            ->addMethods(['getMagentoCategoryId'])
            ->getMock();
        $parentMap->method('getMagentoCategoryId')->willReturn(42);
        $this->categoryMapRepository->method('getByEccubeCategoryId')->with(1)->willReturn($parentMap);

        $mapped = $this->mapper->map($category);

        $this->assertSame(42, $mapped->getMagentoParentId());
    }

    public function testUnimportedParentThrows(): void
    {
        $category = $this->makeCategory(id: 5, parentId: 1, name: 'Sneakers');
        $this->categoryMapRepository->method('getByEccubeCategoryId')->with(1)->willReturn(null);

        $this->expectException(UnresolvedParentException::class);

        $this->mapper->map($category);
    }

    public function testBlankNameFallsBackToSyntheticName(): void
    {
        $this->config->method('getMagentoRootCategoryId')->willReturn(2);
        $category = $this->makeCategory(id: 7, parentId: null, name: '   ');

        $mapped = $this->mapper->map($category);

        $this->assertSame('category-7', $mapped->getName());
    }

    public function testUrlKeyDelegatesToResolver(): void
    {
        $this->config->method('getMagentoRootCategoryId')->willReturn(2);
        $category = $this->makeCategory(id: 9, parentId: null, name: 'Boots');
        $this->urlKeyResolver->method('resolveForCategory')->with(9)->willReturn('boots-9');

        $mapped = $this->mapper->map($category);

        $this->assertSame('boots-9', $mapped->getUrlKey());
    }

    public function testJapaneseOnlyDescriptionIsUsedWhenEnglishIsEmpty(): void
    {
        $this->config->method('getMagentoRootCategoryId')->willReturn(2);
        $category = $this->makeCategory(id: 3, parentId: null, name: 'Viewport', description: '日本語の説明', descriptionEn: null);

        $mapped = $this->mapper->map($category);

        $this->assertSame('日本語の説明', $mapped->getDescription());
    }

    public function testEnglishDescriptionIsNeverOverwrittenByJapanese(): void
    {
        $this->config->method('getMagentoRootCategoryId')->willReturn(2);
        $category = $this->makeCategory(id: 6, parentId: null, name: 'Others', description: '日本語の説明', descriptionEn: 'English description');

        $mapped = $this->mapper->map($category);

        $this->assertSame('English description', $mapped->getDescription());
    }

    public function testBothDescriptionsEmptyResolvesToNull(): void
    {
        $this->config->method('getMagentoRootCategoryId')->willReturn(2);
        $category = $this->makeCategory(id: 8, parentId: null, name: 'Empty', description: null, descriptionEn: null);

        $mapped = $this->mapper->map($category);

        $this->assertNull($mapped->getDescription());
    }

    private function makeCategory(
        int $id,
        ?int $parentId,
        string $name,
        ?string $description = null,
        ?string $descriptionEn = null
    ): CategoryInterface {
        $category = $this->createMock(CategoryInterface::class);
        $category->method('getId')->willReturn($id);
        $category->method('getParentCategoryId')->willReturn($parentId);
        $category->method('getCategoryNameEn')->willReturn($name);
        $category->method('getSortNo')->willReturn(0);
        $category->method('getDescription')->willReturn($description);
        $category->method('getDescriptionEn')->willReturn($descriptionEn);

        return $category;
    }
}
