<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Reader;

/**
 * Streams EC-CUBE source DTOs in batches. Every Reader in the pipeline
 * (Category, Item, Product, Inventory, Image) implements this so the
 * Importer/Synchronizer layers (later milestones) can consume any of them
 * identically.
 */
interface ReaderInterface
{
    /**
     * Yields one DTO at a time, internally paginating through the source in
     * batches of $batchSize. Starting at $offset lets a command resume a
     * previously interrupted run without re-reading earlier records.
     *
     * @return \Generator<int, object>
     */
    public function read(int $offset = 0, ?int $batchSize = null): \Generator;

    /**
     * Total number of records available at the source, used for progress
     * reporting (e.g. "record 4200 / 27886").
     */
    public function count(): int;
}
