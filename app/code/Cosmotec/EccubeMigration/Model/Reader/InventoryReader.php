<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Reader;

use Cosmotec\EccubeMigration\Api\EccubeConfigProviderInterface;
use Cosmotec\EccubeMigration\Api\ProductClassRepositoryInterface;
use Cosmotec\EccubeMigration\Api\ProductRepositoryInterface;
use Cosmotec\EccubeMigration\Model\DTO\InventoryRecord;
use Cosmotec\EccubeMigration\Logger\ImportLogger;

class InventoryReader extends AbstractReader
{
    public function __construct(
        EccubeConfigProviderInterface $config,
        ImportLogger $logger,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ProductClassRepositoryInterface $productClassRepository
    ) {
        parent::__construct($config, $logger);
    }

    public function count(): int
    {
        return $this->productRepository->countAll();
    }

    protected function fetchBatch(int $offset, int $limit): array
    {
        $products = $this->productRepository->getBatch($offset, $limit);

        return array_map(
            fn ($product): InventoryRecord => new InventoryRecord(
                $product->getId(),
                $product->getProductCode(),
                $product->getStockQuantity(),
                $product->isStockLimitedOnly(),
                $this->productClassRepository->getByProductId($product->getId())
            ),
            $products
        );
    }
}
