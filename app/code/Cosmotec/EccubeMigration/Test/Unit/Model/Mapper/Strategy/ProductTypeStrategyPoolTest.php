<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Test\Unit\Model\Mapper\Strategy;

use Cosmotec\EccubeMigration\Model\Mapper\Strategy\ProductTypeStrategyInterface;
use Cosmotec\EccubeMigration\Model\Mapper\Strategy\ProductTypeStrategyPool;
use PHPUnit\Framework\TestCase;

class ProductTypeStrategyPoolTest extends TestCase
{
    public function testGetWithNoArgumentReturnsDefaultStrategy(): void
    {
        $groupedStrategy = $this->createMock(ProductTypeStrategyInterface::class);
        $pool = new ProductTypeStrategyPool(['grouped' => $groupedStrategy], 'grouped');

        $this->assertSame($groupedStrategy, $pool->get());
    }

    public function testGetByExplicitTypeId(): void
    {
        $groupedStrategy = $this->createMock(ProductTypeStrategyInterface::class);
        $configurableStrategy = $this->createMock(ProductTypeStrategyInterface::class);
        $pool = new ProductTypeStrategyPool([
            'grouped' => $groupedStrategy,
            'configurable' => $configurableStrategy,
        ], 'grouped');

        // Demonstrates the whole point of the Strategy Pattern here: adding
        // a new type to the pool doesn't require touching this class or
        // anything that calls it, only the di.xml wiring.
        $this->assertSame($configurableStrategy, $pool->get('configurable'));
    }

    public function testUnregisteredTypeIdThrows(): void
    {
        $pool = new ProductTypeStrategyPool([], 'grouped');

        $this->expectException(\InvalidArgumentException::class);

        $pool->get('bundle');
    }
}
