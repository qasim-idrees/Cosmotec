<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Mapper;

use Cosmotec\EccubeMigration\Api\Data\InventoryRecordInterface;
use Cosmotec\EccubeMigration\Api\Data\MagentoInventoryInterface;
use Cosmotec\EccubeMigration\Api\ProductMapRepositoryInterface;
use Cosmotec\EccubeMigration\Model\DTO\MagentoInventory;
use Cosmotec\EccubeMigration\Model\Mapper\Exception\UnresolvedParentException;

/**
 * Reconciliation rule (confirmed): dtb_product.stock_quantity is ALWAYS the
 * source of truth for quantity, even when dtb_product_class rows exist for
 * the same product. dtb_product_class.stock is read by InventoryReader
 * (Milestone 2) and validated by InventoryValidator, but deliberately not
 * used for quantity here — this matches the spec's stated confirmed
 * mapping (dtb_product.stock_quantity -> Magento Inventory Qty) exactly,
 * rather than introducing a more elaborate reconciliation the spec didn't
 * ask for.
 */
class InventoryMapper implements MapperInterface
{
    public function __construct(
        private readonly ProductMapRepositoryInterface $productMapRepository
    ) {
    }

    /**
     * @param InventoryRecordInterface $source
     */
    public function map(object $source): MagentoInventoryInterface
    {
        if (!$source instanceof InventoryRecordInterface) {
            throw new \InvalidArgumentException(sprintf(
                'InventoryMapper expects %s, got %s',
                InventoryRecordInterface::class,
                get_debug_type($source)
            ));
        }

        $productMap = $this->productMapRepository->getByEccubeProductId($source->getProductId());

        if ($productMap === null || $productMap->getSku() === null) {
            throw new UnresolvedParentException(sprintf(
                'Cannot map inventory for EC-CUBE product id=%d: it has not been imported into Magento yet.',
                $source->getProductId()
            ));
        }

        $qty = (float) max(0, $source->getStockQuantity() ?? 0);
        $stockManaged = $source->isStockLimitedOnly();
        $inStock = $stockManaged ? $qty > 0 : true;

        return new MagentoInventory(
            $source->getProductId(),
            $productMap->getSku(),
            $qty,
            $inStock,
            $stockManaged
        );
    }
}
