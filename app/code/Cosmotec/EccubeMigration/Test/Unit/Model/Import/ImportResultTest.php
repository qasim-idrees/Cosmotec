<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Test\Unit\Model\Import;

use Cosmotec\EccubeMigration\Model\Import\ImportResult;
use PHPUnit\Framework\TestCase;

class ImportResultTest extends TestCase
{
    public function testStartsAtZero(): void
    {
        $result = new ImportResult();

        $this->assertSame(0, $result->getImported());
        $this->assertSame(0, $result->getUpdated());
        $this->assertSame(0, $result->getSkipped());
        $this->assertSame(0, $result->getErrors());
        $this->assertSame(0, $result->getTotalProcessed());
    }

    public function testIncrementsByOneByDefault(): void
    {
        $result = new ImportResult();

        $result->incrementImported();
        $result->incrementUpdated();
        $result->incrementSkipped();
        $result->incrementErrors();

        $this->assertSame(1, $result->getImported());
        $this->assertSame(1, $result->getUpdated());
        $this->assertSame(1, $result->getSkipped());
        $this->assertSame(1, $result->getErrors());
        $this->assertSame(4, $result->getTotalProcessed());
    }

    public function testIncrementsByAnArbitraryCount(): void
    {
        // ProductRelationImporter's dry-run path reports a whole batch of
        // links at once via this — regression guard for that usage.
        $result = new ImportResult();

        $result->incrementImported(5);
        $result->incrementErrors(3);

        $this->assertSame(5, $result->getImported());
        $this->assertSame(3, $result->getErrors());
        $this->assertSame(8, $result->getTotalProcessed());
    }
}
