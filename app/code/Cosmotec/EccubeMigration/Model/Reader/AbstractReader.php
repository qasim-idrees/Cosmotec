<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Reader;

use Cosmotec\EccubeMigration\Api\EccubeConfigProviderInterface;
use Cosmotec\EccubeMigration\Logger\ImportLogger;

abstract class AbstractReader implements ReaderInterface
{
    public function __construct(
        protected readonly EccubeConfigProviderInterface $config,
        protected readonly ImportLogger $logger
    ) {
    }

    final public function read(int $offset = 0, ?int $batchSize = null): \Generator
    {
        $limit = $batchSize ?? $this->config->getBatchSize();
        $total = $this->count();
        $currentOffset = max(0, $offset);
        $readCount = $currentOffset;

        $this->logger->info(sprintf(
            '%s: starting read at offset %d of %d (batch size %d)',
            static::class,
            $currentOffset,
            $total,
            $limit
        ));

        while (true) {
            $batch = $this->fetchBatch($currentOffset, $limit);

            if ($batch === []) {
                break;
            }

            foreach ($batch as $record) {
                yield $record;
            }

            $readCount += count($batch);
            $currentOffset += $limit;

            $this->logger->info(sprintf(
                '%s: read %d / %d',
                static::class,
                min($readCount, $total),
                $total
            ));

            if (count($batch) < $limit) {
                break;
            }
        }
    }

    /**
     * @return object[]
     */
    abstract protected function fetchBatch(int $offset, int $limit): array;
}
