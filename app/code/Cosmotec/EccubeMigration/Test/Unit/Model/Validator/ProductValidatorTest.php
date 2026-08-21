<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Test\Unit\Model\Validator;

use Cosmotec\EccubeMigration\Api\Data\ItemInterface;
use Cosmotec\EccubeMigration\Api\Data\ProductInterface;
use Cosmotec\EccubeMigration\Api\ItemRepositoryInterface;
use Cosmotec\EccubeMigration\Model\Validator\ProductValidator;
use PHPUnit\Framework\TestCase;

class ProductValidatorTest extends TestCase
{
    private ItemRepositoryInterface $eccubeItemRepository;
    private ProductValidator $validator;

    protected function setUp(): void
    {
        $this->eccubeItemRepository = $this->createMock(ItemRepositoryInterface::class);
        $this->validator = new ProductValidator($this->eccubeItemRepository);
    }

    public function testMinimalValidProductPasses(): void
    {
        $product = $this->makeProduct(name: 'T-Shirt', productCode: null, price: null, stockQuantity: null, itemId: null);

        $result = $this->validator->validate($product);

        $this->assertTrue($result->isValid());
    }

    public function testEmptyProductCodeIsAllowed(): void
    {
        // Some EC-CUBE installs only SKU at the class level; empty
        // product_code is legitimate, not an error.
        $product = $this->makeProduct(name: 'T-Shirt', productCode: '', price: null, stockQuantity: null, itemId: null);

        $result = $this->validator->validate($product);

        $this->assertTrue($result->isValid());
    }

    public function testWhitespaceOnlyProductCodeFails(): void
    {
        $product = $this->makeProduct(name: 'T-Shirt', productCode: '   ', price: null, stockQuantity: null, itemId: null);

        $result = $this->validator->validate($product);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('whitespace-only product_code', $result->getErrorsAsString());
    }

    public function testNegativePriceFails(): void
    {
        $product = $this->makeProduct(name: 'T-Shirt', productCode: 'SKU1', price: '-5.00', stockQuantity: null, itemId: null);

        $result = $this->validator->validate($product);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('negative price', $result->getErrorsAsString());
    }

    public function testNonNumericPriceFails(): void
    {
        $product = $this->makeProduct(name: 'T-Shirt', productCode: 'SKU1', price: 'abc', stockQuantity: null, itemId: null);

        $result = $this->validator->validate($product);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('non-numeric price', $result->getErrorsAsString());
    }

    public function testNegativeStockQuantityFails(): void
    {
        $product = $this->makeProduct(name: 'T-Shirt', productCode: 'SKU1', price: null, stockQuantity: -1, itemId: null);

        $result = $this->validator->validate($product);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('negative stock_quantity', $result->getErrorsAsString());
    }

    public function testItemIdReferencingMissingItemFails(): void
    {
        $product = $this->makeProduct(name: 'T-Shirt', productCode: 'SKU1', price: null, stockQuantity: null, itemId: 42);
        $this->eccubeItemRepository->method('getById')->with(42)->willReturn(null);

        $result = $this->validator->validate($product);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('item_id=42', $result->getErrorsAsString());
    }

    public function testItemIdReferencingExistingItemPasses(): void
    {
        $product = $this->makeProduct(name: 'T-Shirt', productCode: 'SKU1', price: null, stockQuantity: null, itemId: 42);
        $this->eccubeItemRepository->method('getById')->with(42)->willReturn($this->createMock(ItemInterface::class));

        $result = $this->validator->validate($product);

        $this->assertTrue($result->isValid());
    }

    private function makeProduct(
        string $name,
        ?string $productCode,
        ?string $price,
        ?int $stockQuantity,
        ?int $itemId
    ): ProductInterface {
        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(1);
        $product->method('getNameEn')->willReturn($name);
        $product->method('getProductCode')->willReturn($productCode);
        $product->method('getPrice')->willReturn($price);
        $product->method('getStockQuantity')->willReturn($stockQuantity);
        $product->method('getItemId')->willReturn($itemId);

        return $product;
    }
}
