<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Import;

final class ImportContext
{
    public function __construct(
        private readonly string $runId,
        private readonly bool $dryRun,
        private readonly bool $resume,
        private readonly ?int $batchSize = null
    ) {
    }

    public function getRunId(): string
    {
        return $this->runId;
    }

    public function isDryRun(): bool
    {
        return $this->dryRun;
    }

    public function isResume(): bool
    {
        return $this->resume;
    }

    public function getBatchSize(): ?int
    {
        return $this->batchSize;
    }
}
