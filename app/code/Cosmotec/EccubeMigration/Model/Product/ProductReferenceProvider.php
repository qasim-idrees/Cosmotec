<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Product;

use Cosmotec\EccubeMigration\Api\ProductReferenceProviderInterface;
use Cosmotec\EccubeMigration\Model\ProductReferenceMap;
use Cosmotec\EccubeMigration\Model\ResourceModel\ProductReferenceMap\CollectionFactory;

class ProductReferenceProvider implements ProductReferenceProviderInterface
{
    /** @var array<int, ProductReferenceMap[]> */
    private array $cache = [];

    public function __construct(
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    public function getByMagentoProductId(int $magentoProductId): array
    {
        if (isset($this->cache[$magentoProductId])) {
            return $this->cache[$magentoProductId];
        }

        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('magento_product_id', $magentoProductId);
        $collection->addFieldToFilter('status', [
            'in' => [ProductReferenceMap::STATUS_IMPORTED, ProductReferenceMap::STATUS_UPDATED],
        ]);
        $collection->setOrder('sort_no', 'ASC');

        $this->cache[$magentoProductId] = array_values($collection->getItems());

        return $this->cache[$magentoProductId];
    }

    public function getByMagentoProductIds(array $magentoProductIds): array
    {
        $magentoProductIds = array_values(array_unique(array_map('intval', $magentoProductIds)));

        if ($magentoProductIds === []) {
            return [];
        }

        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('magento_product_id', ['in' => $magentoProductIds]);
        $collection->addFieldToFilter('status', [
            'in' => [ProductReferenceMap::STATUS_IMPORTED, ProductReferenceMap::STATUS_UPDATED],
        ]);
        $collection->setOrder('sort_no', 'ASC');

        $grouped = array_fill_keys($magentoProductIds, []);

        foreach ($collection->getItems() as $map) {
            $grouped[(int) $map->getMagentoProductId()][] = $map;
        }

        foreach ($grouped as $productId => $maps) {
            $this->cache[$productId] = $maps;
        }

        return $grouped;
    }
}
