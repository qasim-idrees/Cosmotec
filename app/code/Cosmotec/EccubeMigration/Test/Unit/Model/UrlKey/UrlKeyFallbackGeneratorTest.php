<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Test\Unit\Model\UrlKey;

use Cosmotec\EccubeMigration\Model\UrlKey\UrlKeyFallbackGenerator;
use PHPUnit\Framework\TestCase;

class UrlKeyFallbackGeneratorTest extends TestCase
{
    private UrlKeyFallbackGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new UrlKeyFallbackGenerator();
    }

    public function testResultIsDeterministic(): void
    {
        $this->assertSame(
            $this->generator->generate('category', '絶縁碍子'),
            $this->generator->generate('category', '絶縁碍子')
        );
    }

    public function testResultCarriesThePrefix(): void
    {
        $this->assertStringStartsWith('category-', $this->generator->generate('category', '絶縁碍子'));
        $this->assertStringStartsWith('product-', $this->generator->generate('product', 'フィードスルー'));
        $this->assertStringStartsWith('item-', $this->generator->generate('item', 'テスト'));
    }

    public function testDifferentNamesProduceDifferentResults(): void
    {
        $a = $this->generator->generate('category', '絶縁碍子');
        $b = $this->generator->generate('category', 'フィードスルー');

        $this->assertNotSame($a, $b);
    }

    public function testResultContainsNoEccubeIdOrOtherSourceData(): void
    {
        $result = $this->generator->generate('product', 'サニタリー');

        // The whole point: a pure function of the name, nothing else -
        // no digits from any id/sku could ever appear unless the sha256
        // hash itself happens to contain them, which is fine (it's not
        // meaningful/traceable to any source-system identifier).
        $this->assertMatchesRegularExpression('/^product-[0-9a-f]{12}$/', $result);
    }

    public function testResultNeverEmptyEvenForEmptyInput(): void
    {
        $result = $this->generator->generate('category', '');

        $this->assertNotSame('', $result);
        $this->assertStringStartsWith('category-', $result);
    }
}
