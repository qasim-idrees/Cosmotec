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
            $this->resolveDescription($source),
            $this->slugify($this->resolveName($source), $source->getId())
        );
    }

    private function resolveName(CategoryInterface $source): string
    {
        $name = trim($source->getCategoryNameEn());

        return $name !== '' ? $name : sprintf('category-%d', $source->getId());
    }

    private function resolveDescription(CategoryInterface $source): ?string
    {
        return $source->getDescriptionEn() !== null && trim($source->getDescriptionEn()) !== ''
            ? $source->getDescriptionEn()
            : null;
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

    private function slugify(string $name, int $fallbackId): string
    {
        $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        $ascii = $transliterated !== false ? $transliterated : '';
        $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $ascii));
        $slug = trim($slug, '-');

        return $slug !== '' ? $slug : sprintf('category-%d', $fallbackId);
    }
}
