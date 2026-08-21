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
use Cosmotec\EccubeMigration\Api\Data\ItemInterface;
use Cosmotec\EccubeMigration\Api\Data\MagentoParentProductInterface;
use Cosmotec\EccubeMigration\Api\ItemRepositoryInterface;
use Cosmotec\EccubeMigration\Logger\ImportLogger;
use Cosmotec\EccubeMigration\Model\Config\DefaultAttributeSetProvider;
use Cosmotec\EccubeMigration\Model\Mapper\Strategy\ProductTypeStrategyPool;

class ItemMapper implements MapperInterface
{
    public function __construct(
        private readonly ItemRepositoryInterface $itemRepository,
        private readonly CategoryMapRepositoryInterface $categoryMapRepository,
        private readonly ProductTypeStrategyPool $strategyPool,
        private readonly DefaultAttributeSetProvider $attributeSetProvider,
        private readonly ImportLogger $logger
    ) {
    }

    /**
     * @param ItemInterface $source
     */
    public function map(object $source): MagentoParentProductInterface
    {
        if (!$source instanceof ItemInterface) {
            throw new \InvalidArgumentException(sprintf(
                'ItemMapper expects %s, got %s',
                ItemInterface::class,
                get_debug_type($source)
            ));
        }

        $categoryIds = $this->resolveCategoryIds($source);
        $attributeSetId = $this->attributeSetProvider->getDefaultAttributeSetId();
        $strategy = $this->strategyPool->get();

        return $strategy->build($source, $attributeSetId, $categoryIds);
    }

    /**
     * @return int[]
     */
    private function resolveCategoryIds(ItemInterface $source): array
    {
        $categoryIds = [];

        foreach ($this->itemRepository->getCategoryIdsByItemId($source->getId()) as $eccubeCategoryId) {
            $map = $this->categoryMapRepository->getByEccubeCategoryId($eccubeCategoryId);

            if ($map === null || $map->getMagentoCategoryId() === null) {
                // Category enrichment is best-effort, not blocking: an item
                // whose category hasn't been imported yet (or was skipped)
                // still gets created, just without that category assigned.
                $this->logger->info(sprintf(
                    'Item id=%d: EC-CUBE category id=%d has no Magento mapping yet, skipping category assignment.',
                    $source->getId(),
                    $eccubeCategoryId
                ));

                continue;
            }

            $categoryIds[] = (int) $map->getMagentoCategoryId();
        }

        return $categoryIds;
    }
}
