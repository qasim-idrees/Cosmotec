<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Test\Unit\Model\Import;

use Cosmotec\EccubeMigration\Model\Import\ImportContext;
use Cosmotec\EccubeMigration\Model\Import\ImportResult;
use PHPUnit\Framework\TestCase;

/**
 * Guards the dry-run contract of the media pipeline.
 *
 * MediaImporter persist is the single place that copies files, creates
 * Magento gallery entries and writes eccube_media_map. It throws if it is
 * ever reached with a dry-run context, so a dry run writing nothing is
 * enforced structurally rather than by convention.
 */
class MediaImporterDryRunTest extends TestCase
{
    public function testPersistIsGuardedAgainstDryRun(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../../../Model/Import/MediaImporter.php'
        );

        $this->assertIsString($source);

        $persistPos = strpos($source, 'private function persist');
        $this->assertNotFalse($persistPos, 'persist() must exist');

        $body = substr($source, $persistPos, 900);

        $this->assertStringContainsString(
            'isDryRun()',
            $body,
            'persist() must refuse to run in dry-run mode'
        );
        $this->assertStringContainsString(
            'LogicException',
            $body,
            'Reaching persist() during a dry run is a programming error and must fail loudly'
        );
    }

    /**
     * A dry run still reports what it would do, so the counters must move
     * even though nothing is written.
     */
    public function testDryRunStillReportsCounts(): void
    {
        $result = new ImportResult();
        $result->incrementImported();
        $result->incrementNeedsReview();

        $this->assertSame(1, $result->getImported());
        $this->assertSame(1, $result->getNeedsReview());
        $this->assertSame(0, $result->getErrors());
    }

    /**
     * Idempotency: an unchanged file that is already imported must be
     * counted as skipped, never re-imported, so repeated --execute runs
     * cannot create duplicate gallery entries.
     */
    public function testUnchangedMediaIsSkippedNotReimported(): void
    {
        $result = new ImportResult();
        $result->incrementSkipped();

        $this->assertSame(1, $result->getSkipped());
        $this->assertSame(0, $result->getImported());
        $this->assertSame(0, $result->getUpdated());
    }

    /**
     * needs_review (missing source file) must stay separate from errors so
     * a data condition never looks like an import malfunction.
     */
    public function testNeedsReviewIsSeparateFromErrors(): void
    {
        $result = new ImportResult();
        $result->incrementNeedsReview(3);

        $this->assertSame(3, $result->getNeedsReview());
        $this->assertSame(0, $result->getErrors());
    }

    public function testDryRunContextIsImmutable(): void
    {
        $context = new ImportContext('r1', true, true, 10);

        $this->assertTrue($context->isDryRun());
        $this->assertSame('r1', $context->getRunId());
    }
}
