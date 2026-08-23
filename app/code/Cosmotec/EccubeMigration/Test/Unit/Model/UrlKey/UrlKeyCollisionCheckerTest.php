<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Test\Unit\Model\UrlKey;

use Cosmotec\EccubeMigration\Model\UrlKey\UrlKeyCollisionChecker;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\UrlRewrite\Model\UrlFinderInterface;
use Magento\UrlRewrite\Service\V1\Data\UrlRewrite;
use PHPUnit\Framework\TestCase;

class UrlKeyCollisionCheckerTest extends TestCase
{
    private UrlFinderInterface $urlFinder;
    private StoreManagerInterface $storeManager;
    private ScopeConfigInterface $scopeConfig;
    private UrlKeyCollisionChecker $checker;

    protected function setUp(): void
    {
        $this->urlFinder = $this->createMock(UrlFinderInterface::class);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->checker = new UrlKeyCollisionChecker($this->urlFinder, $this->storeManager, $this->scopeConfig);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $this->storeManager->method('getStores')->willReturn([$store]);
        $this->scopeConfig->method('getValue')->willReturn('.html');
    }

    public function testEmptyUrlKeyNeverCollides(): void
    {
        $this->urlFinder->expects($this->never())->method('findOneByData');

        $this->assertFalse($this->checker->wouldCollide(''));
    }

    public function testNoExistingRewriteMeansNoCollision(): void
    {
        $this->urlFinder->method('findOneByData')->willReturn(null);

        $this->assertFalse($this->checker->wouldCollide('brand-new-widget'));
    }

    public function testExistingRewriteAtTheComputedRequestPathMeansCollision(): void
    {
        $rewrite = $this->createMock(UrlRewrite::class);
        $this->urlFinder->expects($this->once())
            ->method('findOneByData')
            ->with([
                UrlRewrite::REQUEST_PATH => 'widget.html',
                UrlRewrite::STORE_ID => 1,
            ])
            ->willReturn($rewrite);

        $this->assertTrue($this->checker->wouldCollide('widget'));
    }
}
