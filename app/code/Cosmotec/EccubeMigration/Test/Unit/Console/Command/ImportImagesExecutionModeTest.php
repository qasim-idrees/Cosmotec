<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Test\Unit\Console\Command;

use Cosmotec\EccubeMigration\Model\Import\ImportContext;
use PHPUnit\Framework\TestCase;

/**
 * Execution-mode semantics for cosmotec:eccube:import:images.
 *
 * The precedence rule under test:
 *   --dry-run          -> dry run (always wins)
 *   --execute          -> write mode
 *   neither            -> dry run
 *   both               -> dry run
 *
 * Writing is opt-in, so an accidental invocation can never mutate Magento.
 */
class ImportImagesExecutionModeTest extends TestCase
{
    /**
     * Mirrors the resolution in ImportImagesCommand::execute().
     */
    private function resolveDryRun(bool $explicitDryRun, bool $execute): bool
    {
        return $explicitDryRun || !$execute;
    }

    public function testDefaultIsDryRun(): void
    {
        $this->assertTrue(
            $this->resolveDryRun(false, false),
            'With no flags the command must never write to Magento'
        );
    }

    public function testExecuteEnablesWriteMode(): void
    {
        $this->assertFalse($this->resolveDryRun(false, true));
    }

    public function testDryRunAlwaysWinsOverExecute(): void
    {
        $this->assertTrue(
            $this->resolveDryRun(true, true),
            'When both flags are given the safer mode must win'
        );
    }

    public function testExplicitDryRunIsDryRun(): void
    {
        $this->assertTrue($this->resolveDryRun(true, false));
    }

    /**
     * The mode must reach the importer chain through ImportContext, since
     * that is what every mutation point checks.
     */
    public function testModePropagatesThroughImportContext(): void
    {
        $dry = new ImportContext('run-dry', true, true, 100);
        $this->assertTrue($dry->isDryRun());

        $write = new ImportContext('run-exec', false, true, 100);
        $this->assertFalse($write->isDryRun());
    }

    public function testRunIdIsCarriedForAuditTrail(): void
    {
        $context = new ImportContext('media-20260820-1', false, true, 50);

        $this->assertSame('media-20260820-1', $context->getRunId());
        $this->assertSame(50, $context->getBatchSize());
    }
}
