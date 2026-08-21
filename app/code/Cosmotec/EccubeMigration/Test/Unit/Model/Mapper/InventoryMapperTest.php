<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Test\Unit\Model\Mapper;

use Cosmotec\EccubeMigration\Api\Data\InventoryRecordInterface;
use Cosmotec\EccubeMigration\Api\Data\ProductClassInterface;
use Cosmotec\EccubeMigration\Api\ProductMapRepositoryInterface;
use Cosmotec\EccubeMigration\Model\Mapper\Exception\UnresolvedParentException;
use Cosmotec\EccubeMigration\Model\Mapper\InventoryMapper;
use Cosmotec\EccubeMigration\Model\ProductMap;
use PHPUnit\Framework\TestCase;

class InventoryMapperTest extends TestCase
{
    private ProductMapRepositoryInterface $productMapRepository;
    private InventoryMapper $mapper;

    protected function setUp(): void
    {
        $this->productMapRepository = $this->createMock(ProductMapRepositoryInterface::class);
        $this->mapper = new InventoryMapper($this->productMapRepository);
    }

    /**
     * The confirmed rule: dtb_product.stock_quantity ALWAYS wins, even when
     * dtb_product_class rows report a completely different figure. This is
     * the single most important behavior in the whole Inventory milestone.
     */
    public function testProductStockQuantityWinsOverClassStockEvenWhenClassesPresent(): void
    {
        $productClass = $this->createMock(ProductClassInterface::class);
        $productClass->method('getStock')->willReturn('999');

        $record = $this->makeRecord(productId: 10, stockQuantity: 5, stockLimitedOnly: true, productClasses: [$productClass]);
        $this->productMapRepository->method('getByEccubeProductId')->with(10)->willReturn($this->makeProductMap('SKU-10'));

        $mapped = $this->mapper->map($record);

        $this->assertSame(5.0, $mapped->getQty());
    }

    public function testNullStockQuantityBecomesZero(): void
    {
        $record = $this->makeRecord(productId: 10, stockQuantity: null, stockLimitedOnly: true, productClasses: []);
        $this->productMapRepository->method('getByEccubeProductId')->willReturn($this->makeProductMap('SKU-10'));

        $mapped = $this->mapper->map($record);

        $this->assertSame(0.0, $mapped->getQty());
        $this->assertFalse($mapped->isInStock());
    }

    public function testUnlimitedStockIsAlwaysInStockRegardlessOfQty(): void
    {
        $record = $this->makeRecord(productId: 10, stockQuantity: 0, stockLimitedOnly: false, productClasses: []);
        $this->productMapRepository->method('getByEccubeProductId')->willReturn($this->makeProductMap('SKU-10'));

        $mapped = $this->mapper->map($record);

        $this->assertTrue($mapped->isInStock());
        $this->assertFalse($mapped->isStockManaged());
    }

    public function testManagedStockIsOutOfStockWhenQtyIsZero(): void
    {
        $record = $this->makeRecord(productId: 10, stockQuantity: 0, stockLimitedOnly: true, productClasses: []);
        $this->productMapRepository->method('getByEccubeProductId')->willReturn($this->makeProductMap('SKU-10'));

        $mapped = $this->mapper->map($record);

        $this->assertFalse($mapped->isInStock());
    }

    public function testThrowsWhenProductNotYetImported(): void
    {
        $record = $this->makeRecord(productId: 10, stockQuantity: 5, stockLimitedOnly: true, productClasses: []);
        $this->productMapRepository->method('getByEccubeProductId')->with(10)->willReturn(null);

        $this->expectException(UnresolvedParentException::class);

        $this->mapper->map($record);
    }

    /**
     * @param ProductClassInterface[] $productClasses
     */
    private function makeRecord(int $productId, ?int $stockQuantity, bool $stockLimitedOnly, array $productClasses): InventoryRecordInterface
    {
        $record = $this->createMock(InventoryRecordInterface::class);
        $record->method('getProductId')->willReturn($productId);
        $record->method('getStockQuantity')->willReturn($stockQuantity);
        $record->method('isStockLimitedOnly')->willReturn($stockLimitedOnly);
        $record->method('getProductClasses')->willReturn($productClasses);

        return $record;
    }

    /**
     * ProductMap extends \Magento\Framework\Model\AbstractModel, whose
     * getSku()/setSku() are magic (__call-based), not literally declared —
     * createMock() alone can't stub those, so addMethods() is used
     * instead. This is the standard technique for mocking Magento
     * AbstractModel-based classes.
     */
    private function makeProductMap(string $sku): ProductMap
    {
        $map = $this->getMockBuilder(ProductMap::class)
            ->disableOriginalConstructor()
            ->addMethods(['getSku'])
            ->getMock();
        $map->method('getSku')->willReturn($sku);

        return $map;
    }
}
