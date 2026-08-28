<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Api;

use Cosmotec\EccubeMigration\Model\ProductReferenceMap;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;

interface ProductReferenceMapRepositoryInterface
{
    public function getByReferenceId(int $eccubeReferenceId): ?ProductReferenceMap;

    /**
     * Direct entity_id lookup, used by the admin "EC-CUBE Documents"
     * Document Name/Reference Link (1)/(2) save action - see Task 5.5/5.6.
     *
     * @throws NoSuchEntityException
     */
    public function getById(int $entityId): ProductReferenceMap;

    /**
     * @throws CouldNotSaveException
     */
    public function save(ProductReferenceMap $map): ProductReferenceMap;

    /**
     * All mappings for a product, so references removed at source can be
     * marked obsolete without touching the ones that remain.
     *
     * @return ProductReferenceMap[]
     */
    public function getByProductId(int $eccubeProductId): array;

    /**
     * @return ProductReferenceMap[]
     */
    public function getFailed(int $limit): array;

    public function countByStatus(string $status): int;
}
