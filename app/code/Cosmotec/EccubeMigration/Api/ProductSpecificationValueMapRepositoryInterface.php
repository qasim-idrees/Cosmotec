<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Api;

use Cosmotec\EccubeMigration\Model\ProductSpecificationValueMap;
use Magento\Framework\Exception\CouldNotSaveException;

/**
 * PENDING DESIGN - see Model\Specification\MultiValueSpecificationRegistry
 * and BUILD_STATUS.md.
 */
interface ProductSpecificationValueMapRepositoryInterface
{
    public function getBySourceRowId(int $eccubeProductSpecificationClassId): ?ProductSpecificationValueMap;

    /**
     * @throws CouldNotSaveException
     */
    public function save(ProductSpecificationValueMap $map): ProductSpecificationValueMap;

    /**
     * Every value already recorded for one product+specification, ordered
     * by position - used to detect the current position count when a new
     * value arrives, and to build the multiselect option list.
     *
     * @return ProductSpecificationValueMap[]
     */
    public function getByProductAndSpecification(int $eccubeProductId, int $eccubeSpecificationId): array;

    /**
     * Deletes every positional row for this product+specification whose
     * source row id (eccube_product_specification_class_id) is NOT in
     * $currentSourceRowIds - i.e. values that no longer exist at the
     * EC-CUBE source. Pass an empty array to delete every row for this
     * product+specification (the specification was removed entirely).
     *
     * @param int[] $currentSourceRowIds
     * @return int number of rows deleted
     */
    public function deleteOrphaned(int $eccubeProductId, int $eccubeSpecificationId, array $currentSourceRowIds): int;
}
