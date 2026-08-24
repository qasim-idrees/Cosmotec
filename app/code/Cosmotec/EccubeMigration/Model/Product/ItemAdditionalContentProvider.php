<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Product;

use Cosmotec\EccubeMigration\Api\ItemAdditionalContentMapRepositoryInterface;
use Cosmotec\EccubeMigration\Api\ItemAdditionalContentProviderInterface;
use Cosmotec\EccubeMigration\Model\ItemAdditionalContentMap;

class ItemAdditionalContentProvider implements ItemAdditionalContentProviderInterface
{
    /** @var array<int, ItemAdditionalContentMap[]> */
    private array $cache = [];

    public function __construct(
        private readonly ItemAdditionalContentMapRepositoryInterface $mapRepository
    ) {
    }

    public function getByMagentoProductId(int $magentoProductId): array
    {
        if (!isset($this->cache[$magentoProductId])) {
            $this->cache[$magentoProductId] = $this->mapRepository->getByMagentoProductId($magentoProductId);
        }

        return $this->cache[$magentoProductId];
    }
}
