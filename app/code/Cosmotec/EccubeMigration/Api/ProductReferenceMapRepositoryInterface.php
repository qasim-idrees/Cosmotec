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

interface ProductReferenceMapRepositoryInterface
{
    public function getByReferenceId(int $eccubeReferenceId): ?ProductReferenceMap;

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
