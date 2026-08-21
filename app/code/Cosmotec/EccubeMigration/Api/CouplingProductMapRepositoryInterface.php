<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Api;

use Cosmotec\EccubeMigration\Model\CouplingProductMap;
use Magento\Framework\Exception\CouldNotSaveException;

interface CouplingProductMapRepositoryInterface
{
    public function getByCouplingId(int $eccubeCouplingId): ?CouplingProductMap;

    /**
     * @throws CouldNotSaveException
     */
    public function save(CouplingProductMap $map): CouplingProductMap;

    /**
     * All mappings declared for one parent Item, so couplings removed at
     * source can be marked obsolete without touching the ones that remain.
     *
     * @return CouplingProductMap[]
     */
    public function getByItemId(int $eccubeItemId): array;

    /**
     * @return CouplingProductMap[]
     */
    public function getFailed(int $limit): array;

    public function countByStatus(string $status): int;

    /**
     * Distinct EC-CUBE parent item ids with at least one mapping, paged -
     * lets sync walk obsolete-detection without materialising the whole
     * table, mirroring MediaRepository::getOwnerIds().
     *
     * @return int[]
     */
    public function getDistinctItemIds(int $offset, int $limit): array;
}
