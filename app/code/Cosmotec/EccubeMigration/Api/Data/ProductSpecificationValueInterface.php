<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Api\Data;

/**
 * One dtb_product_specification_class row (child/Simple Product value).
 *
 * CRITICAL schema correction, verified live via DESCRIBE against the real
 * production table (do not assume the design in docs/ATTRIBUTE_MIGRATION_PLAN.md
 * §B applies here): dtb_product_specification_class has NO sort_no column
 * and NO specification_id column. Its real columns are
 * id, specification_class_id, product_id, creator_id, create_date,
 * update_date, discriminator_type. specification_id is only reachable by
 * joining specification_class_id -> dtb_specification_class.specification_id.
 * The sort_no=0/1 positional example in the docs describes a DIFFERENT
 * table (dtb_item_specification, the per-item PRODUCT-scope declaration),
 * not the per-product value rows this interface represents.
 *
 * Consequence: for the ~329 (product, specification) pairs holding more
 * than one value, there is no explicit source ordering column. getId()
 * (row insertion order) is the only available deterministic tie-break,
 * matching the same fallback already used for dtb_product_reference
 * (see ProductReferenceMap - "source has no sort column; source id order
 * is used").
 */
interface ProductSpecificationValueInterface
{
    public function getId(): int;

    public function getProductId(): int;

    public function getSpecificationId(): int;

    /**
     * dtb_specification_class.id - the chosen option.
     */
    public function getSpecificationClassId(): int;
}
