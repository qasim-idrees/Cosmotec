<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Api;

use Cosmotec\EccubeMigration\Model\AttributeSetMap;
use Magento\Framework\Exception\CouldNotSaveException;

interface AttributeSetMapRepositoryInterface
{
    public function getByTopLevelCategoryId(int $eccubeCategoryId): ?AttributeSetMap;

    /**
     * @throws CouldNotSaveException
     */
    public function save(AttributeSetMap $map): AttributeSetMap;

    /**
     * @return AttributeSetMap[]
     */
    public function getAll(): array;

    public function countByStatus(string $status): int;
}
