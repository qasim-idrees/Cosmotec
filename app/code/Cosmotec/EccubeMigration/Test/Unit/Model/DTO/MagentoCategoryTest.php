<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Test\Unit\Model\DTO;

use Cosmotec\EccubeMigration\Model\DTO\MagentoCategory;
use PHPUnit\Framework\TestCase;

class MagentoCategoryTest extends TestCase
{
    public function testIdenticalInputsProduceTheSameHash(): void
    {
        $a = new MagentoCategory(1, 'Shoes', 2, true, true, 10, 'desc', 'shoes');
        $b = new MagentoCategory(1, 'Shoes', 2, true, true, 10, 'desc', 'shoes');

        $this->assertSame($a->getContentHash(), $b->getContentHash());
    }

    public function testDifferingNameProducesADifferentHash(): void
    {
        // CategorySync (Milestone 8) depends on this: a changed name must
        // change the hash, or the sync would wrongly treat it as unchanged
        // and skip writing the update.
        $a = new MagentoCategory(1, 'Shoes', 2, true, true, 10, 'desc', 'shoes');
        $b = new MagentoCategory(1, 'Boots', 2, true, true, 10, 'desc', 'shoes');

        $this->assertNotSame($a->getContentHash(), $b->getContentHash());
    }

    public function testDifferingParentProducesADifferentHash(): void
    {
        $a = new MagentoCategory(1, 'Shoes', 2, true, true, 10, 'desc', 'shoes');
        $b = new MagentoCategory(1, 'Shoes', 3, true, true, 10, 'desc', 'shoes');

        $this->assertNotSame($a->getContentHash(), $b->getContentHash());
    }

    public function testHashIsSha256Length(): void
    {
        $category = new MagentoCategory(1, 'Shoes', 2, true, true, 10, null, null);

        $this->assertSame(64, strlen($category->getContentHash()));
    }
}
