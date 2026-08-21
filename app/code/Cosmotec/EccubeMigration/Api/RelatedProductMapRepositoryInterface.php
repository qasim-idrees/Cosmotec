<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Api;

use Cosmotec\EccubeMigration\Model\RelatedProductMap;
use Magento\Framework\Exception\CouldNotSaveException;

interface RelatedProductMapRepositoryInterface
{
    public function getByRelatedId(int $eccubeRelatedId): ?RelatedProductMap;

    /**
     * @throws CouldNotSaveException
     */
    public function save(RelatedProductMap $map): RelatedProductMap;

    /**
     * @return RelatedProductMap[]
     */
    public function getByProductId(int $eccubeProductId): array;

    /**
     * @return RelatedProductMap[]
     */
    public function getFailed(int $limit): array;

    public function countByStatus(string $status): int;

    /**
     * Distinct EC-CUBE source product ids with at least one mapping,
     * paged - lets sync walk obsolete-detection without materialising the
     * whole table, mirroring MediaRepository::getOwnerIds().
     *
     * @return int[]
     */
    public function getDistinctProductIds(int $offset, int $limit): array;
}
