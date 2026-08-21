<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Reader;

use Cosmotec\EccubeMigration\Api\Data\MediaFileInterface;
use Cosmotec\EccubeMigration\Api\EccubeConfigProviderInterface;
use Cosmotec\EccubeMigration\Api\MediaRepositoryInterface;
use Cosmotec\EccubeMigration\Logger\ImportLogger;
use Cosmotec\EccubeMigration\Model\Media\MediaRelationType;

/**
 * Streams EC-CUBE media one relation at a time, batched and resumable.
 *
 * Deliberately not an AbstractReader subclass: that base class assumes a
 * single unparameterised source, whereas media is read per relation type.
 */
class MediaReader
{
    public function __construct(
        private readonly MediaRepositoryInterface $mediaRepository,
        private readonly EccubeConfigProviderInterface $config,
        private readonly ImportLogger $logger
    ) {
    }

    /**
     * @return \Generator<int, MediaFileInterface>
     */
    public function read(MediaRelationType $relationType, int $offset = 0, ?int $batchSize = null): \Generator
    {
        $limit = $batchSize ?? $this->config->getBatchSize();
        $total = $this->mediaRepository->countByRelation($relationType);
        $currentOffset = max(0, $offset);

        $this->logger->info(sprintf(
            'MediaReader[%s]: %d file(s) to read from %s (batch %d, offset %d)',
            $relationType->value,
            $total,
            $relationType->joinTable(),
            $limit,
            $currentOffset
        ));

        while (true) {
            $batch = $this->mediaRepository->getBatch($relationType, $currentOffset, $limit);

            if ($batch === []) {
                break;
            }

            foreach ($batch as $file) {
                yield $file;
            }

            $currentOffset += $limit;

            if (count($batch) < $limit) {
                break;
            }
        }
    }

    public function count(MediaRelationType $relationType): int
    {
        return $this->mediaRepository->countByRelation($relationType);
    }
}
