<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Test\Unit\Model\Validator;

use Cosmotec\EccubeMigration\Api\CategoryRepositoryInterface;
use Cosmotec\EccubeMigration\Api\Data\CategoryInterface;
use Cosmotec\EccubeMigration\Model\Validator\CategoryValidator;
use PHPUnit\Framework\TestCase;

class CategoryValidatorTest extends TestCase
{
    private CategoryRepositoryInterface $eccubeCategoryRepository;
    private CategoryValidator $validator;

    protected function setUp(): void
    {
        $this->eccubeCategoryRepository = $this->createMock(CategoryRepositoryInterface::class);
        $this->validator = new CategoryValidator($this->eccubeCategoryRepository);
    }

    public function testValidCategoryWithNoParentPasses(): void
    {
        $category = $this->makeCategory(1, null, 'Shoes');

        $result = $this->validator->validate($category);

        $this->assertTrue($result->isValid());
    }

    public function testEmptyCategoryNameFails(): void
    {
        $category = $this->makeCategory(1, null, '   ');

        $result = $this->validator->validate($category);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('empty category_name_en', $result->getErrorsAsString());
    }

    public function testParentReferencingNonexistentCategoryFails(): void
    {
        $category = $this->makeCategory(5, 99, 'Sneakers');
        $this->eccubeCategoryRepository->method('getById')->with(99)->willReturn(null);

        $result = $this->validator->validate($category);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('parent_category_id=99', $result->getErrorsAsString());
    }

    public function testParentThatExistsInSourcePassesRegardlessOfImportState(): void
    {
        // Deliberately does NOT check whether the parent has been imported
        // into Magento yet — that's the Mapper's job, not the Validator's,
        // precisely so a pre-import validate:categories run doesn't falsely
        // flag every non-root category as invalid.
        $category = $this->makeCategory(5, 1, 'Sneakers');
        $parent = $this->makeCategory(1, null, 'Shoes');
        $this->eccubeCategoryRepository->method('getById')->with(1)->willReturn($parent);

        $result = $this->validator->validate($category);

        $this->assertTrue($result->isValid());
    }

    public function testRejectsWrongType(): void
    {
        $result = $this->validator->validate(new \stdClass());

        $this->assertFalse($result->isValid());
    }

    private function makeCategory(int $id, ?int $parentId, string $name): CategoryInterface
    {
        $category = $this->createMock(CategoryInterface::class);
        $category->method('getId')->willReturn($id);
        $category->method('getParentCategoryId')->willReturn($parentId);
        $category->method('getCategoryNameEn')->willReturn($name);

        return $category;
    }
}
