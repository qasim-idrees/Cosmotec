<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Product;

use Cosmotec\EccubeMigration\Api\CouplingProductProviderInterface;
use Cosmotec\EccubeMigration\Model\CouplingProductMap;
use Cosmotec\EccubeMigration\Model\ResourceModel\CouplingProductMap\CollectionFactory;

class CouplingProductProvider implements CouplingProductProviderInterface
{
    /** @var array<int, CouplingProductMap[]> */
    private array $cache = [];

    public function __construct(
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    public function getByMagentoParentProductId(int $magentoParentProductId): array
    {
        if (isset($this->cache[$magentoParentProductId])) {
            return $this->cache[$magentoParentProductId];
        }

        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('magento_parent_product_id', $magentoParentProductId);
        $collection->addFieldToFilter('status', [
            'in' => [CouplingProductMap::STATUS_IMPORTED, CouplingProductMap::STATUS_UPDATED],
        ]);
        $collection->setOrder('sort_no', 'ASC');

        $this->cache[$magentoParentProductId] = array_values($collection->getItems());

        return $this->cache[$magentoParentProductId];
    }
}
