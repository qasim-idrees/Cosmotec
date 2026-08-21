<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Import;

final class ImportResult
{
    private int $imported = 0;
    private int $updated = 0;
    private int $skipped = 0;
    private int $errors = 0;
    private int $needsReview = 0;

    public function incrementImported(int $count = 1): void
    {
        $this->imported += $count;
    }

    public function incrementUpdated(int $count = 1): void
    {
        $this->updated += $count;
    }

    public function incrementSkipped(int $count = 1): void
    {
        $this->skipped += $count;
    }

    public function incrementErrors(int $count = 1): void
    {
        $this->errors += $count;
    }

    /**
     * Distinct from a code/processing error: a data condition needing
     * human follow-up (e.g. a source file genuinely missing on disk) that
     * does not indicate a bug. Kept separate so real errors are never
     * hidden inside an inflated "errors" count.
     */
    public function incrementNeedsReview(int $count = 1): void
    {
        $this->needsReview += $count;
    }

    public function getImported(): int
    {
        return $this->imported;
    }

    public function getUpdated(): int
    {
        return $this->updated;
    }

    public function getSkipped(): int
    {
        return $this->skipped;
    }

    public function getErrors(): int
    {
        return $this->errors;
    }

    public function getNeedsReview(): int
    {
        return $this->needsReview;
    }

    public function getTotalProcessed(): int
    {
        return $this->imported + $this->updated + $this->skipped + $this->errors + $this->needsReview;
    }
}
