<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Api;

use Cosmotec\EccubeMigration\Model\SpecificationMap;
use Cosmotec\EccubeMigration\Model\SpecificationOptionMap;
use Magento\Framework\Exception\CouldNotSaveException;

/**
 * Persistent EC-CUBE specification/option <-> Magento attribute/option
 * mapping. This is what makes attribute creation idempotent (§27): a
 * second run must never create a duplicate Magento attribute.
 */
interface SpecificationMapRepositoryInterface
{
    public function getBySpecificationId(int $eccubeSpecificationId): ?SpecificationMap;

    /**
     * @throws CouldNotSaveException
     */
    public function save(SpecificationMap $map): SpecificationMap;

    public function countByStatus(string $status): int;

    public function getOptionByClassId(int $eccubeSpecificationClassId): ?SpecificationOptionMap;

    /**
     * @throws CouldNotSaveException
     */
    public function saveOption(SpecificationOptionMap $map): SpecificationOptionMap;

    /**
     * @return SpecificationOptionMap[]
     */
    public function getOptionsBySpecificationId(int $eccubeSpecificationId): array;
}
