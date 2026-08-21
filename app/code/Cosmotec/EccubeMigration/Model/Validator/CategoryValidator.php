<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Validator;

use Cosmotec\EccubeMigration\Api\CategoryRepositoryInterface as EccubeCategoryRepositoryInterface;
use Cosmotec\EccubeMigration\Api\Data\CategoryInterface;

class CategoryValidator implements ValidatorInterface
{
    public function __construct(
        private readonly EccubeCategoryRepositoryInterface $eccubeCategoryRepository
    ) {
    }

    public function validate(object $source): ValidationResult
    {
        if (!$source instanceof CategoryInterface) {
            return ValidationResult::failure([
                sprintf('Expected %s, got %s', CategoryInterface::class, get_debug_type($source)),
            ]);
        }

        $errors = [];

        if (trim($source->getCategoryNameEn()) === '') {
            $errors[] = sprintf('Category id=%d has an empty category_name_en', $source->getId());
        }

        $parentId = $source->getParentCategoryId();

        if ($parentId !== null && $this->eccubeCategoryRepository->getById($parentId) === null) {
            $errors[] = sprintf(
                'Category id=%d references parent_category_id=%d which does not exist in dtb_category',
                $source->getId(),
                $parentId
            );
        }

        return $errors === [] ? ValidationResult::success() : ValidationResult::failure($errors);
    }
}
