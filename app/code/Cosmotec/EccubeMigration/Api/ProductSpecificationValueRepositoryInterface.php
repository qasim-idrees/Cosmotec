<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Api;

use Cosmotec\EccubeMigration\Api\Data\ProductSpecificationValueInterface;
use Cosmotec\EccubeMigration\Model\Connection\EccubeConnectionException;

/**
 * Read-only. All SQL for PRODUCT-scope (child) specification values lives
 * here.
 */
interface ProductSpecificationValueRepositoryInterface
{
    /**
     * All dtb_product_specification_class rows for one product, resolved
     * through the specification_class join to know which specification
     * each value belongs to. Ordered by specification id then row id -
     * see ProductSpecificationValueInterface for why row id (not a source
     * sort_no, which does not exist on this table) is the ordering
     * fallback for the rare multi-value case.
     *
     * @return ProductSpecificationValueInterface[]
     * @throws \Cosmotec\EccubeMigration\Model\Connection\EccubeConnectionException
     */
    public function getByProductId(int $productId): array;

    /**
     * Distinct product ids that have at least one specification value,
     * paged. Lets the value importer walk the whole table without one
     * query per EC-CUBE product id that has no specification data at all.
     *
     * @return int[]
     * @throws \Cosmotec\EccubeMigration\Model\Connection\EccubeConnectionException
     */
    public function getProductIdsWithValues(int $offset, int $limit): array;

    /**
     * @throws \Cosmotec\EccubeMigration\Model\Connection\EccubeConnectionException
     */
    public function countProductsWithValues(): int;
}
