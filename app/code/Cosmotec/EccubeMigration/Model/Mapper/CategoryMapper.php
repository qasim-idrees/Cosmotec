<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Mapper;

use Cosmotec\EccubeMigration\Api\CategoryMapRepositoryInterface;
use Cosmotec\EccubeMigration\Api\Data\CategoryInterface;
use Cosmotec\EccubeMigration\Api\EccubeConfigProviderInterface;
use Cosmotec\EccubeMigration\Model\DTO\MagentoCategory;
use Cosmotec\EccubeMigration\Model\Mapper\Exception\UnresolvedParentException;

class CategoryMapper implements MapperInterface
{
    public function __construct(
        private readonly EccubeConfigProviderInterface $config,
        private readonly CategoryMapRepositoryInterface $categoryMapRepository
    ) {
    }

    /**
     * @param CategoryInterface $source
     */
    public function map(object $source): MagentoCategory
    {
        if (!$source instanceof CategoryInterface) {
            throw new \InvalidArgumentException(sprintf(
                'CategoryMapper expects %s, got %s',
                CategoryInterface::class,
                get_debug_type($source)
            ));
        }

        return new MagentoCategory(
            $source->getId(),
            $this->resolveName($source),
            $this->resolveMagentoParentId($source),
            true,
            true,
            $source->getSortNo(),
            $this->resolveDescription($source)
        );
    }

    private function resolveName(CategoryInterface $source): string
    {
        $name = trim($source->getCategoryNameEn());

        return $name !== '' ? $name : sprintf('category-%d', $source->getId());
    }

    /**
     * English preferred, Japanese fallback when English is unavailable -
     * per project language policy (CLAUDE.md "Language"), same pattern
     * already used for product/item names. A populated English
     * description is never overwritten by the Japanese one. Returns null
     * only when both languages are genuinely empty, so the caller can
     * distinguish "no description" from "description exists, just not in
     * English" and correctly clear a Magento description when the source
     * has none (see CategoryImporter::persist()).
     */
    private function resolveDescription(CategoryInterface $source): ?string
    {
        $descriptionEn = $source->getDescriptionEn();

        if ($descriptionEn !== null && trim($descriptionEn) !== '') {
            return $descriptionEn;
        }

        $description = $source->getDescription();

        return $description !== null && trim($description) !== '' ? $description : null;
    }

    /**
     * @throws UnresolvedParentException if a non-root parent hasn't been imported yet.
     *         CategoryValidator should already have rejected this case before the
     *         Mapper is ever called, but the Mapper does not trust that blindly.
     */
    private function resolveMagentoParentId(CategoryInterface $source): int
    {
        $parentId = $source->getParentCategoryId();

        if ($parentId === null) {
            return $this->config->getMagentoRootCategoryId();
        }

        $parentMap = $this->categoryMapRepository->getByEccubeCategoryId($parentId);

        if ($parentMap === null || $parentMap->getMagentoCategoryId() === null) {
            throw new UnresolvedParentException(sprintf(
                'Cannot map EC-CUBE category id=%d: parent id=%d has no Magento category mapped yet.',
                $source->getId(),
                $parentId
            ));
        }

        return (int) $parentMap->getMagentoCategoryId();
    }
}
