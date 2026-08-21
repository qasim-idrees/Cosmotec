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
 * One dtb_item_specification row (type=1, ITEM/parent scope) resolved to
 * the actual option it carries.
 *
 * Schema note (verified live against production, not assumed):
 * dtb_item_specification does not store the chosen option directly - it
 * points via item_specification_class_id to a dtb_item_specification_class
 * row, which in turn points via specification_class_id to the real
 * dtb_specification_class option. This DTO represents the fully-resolved
 * triple after both joins, so callers never need to know the indirection
 * exists.
 *
 * A specification can legitimately appear more than once for the same
 * item (the SetSpecificationRule "2 pieces" duplication, see
 * ATTRIBUTE_MIGRATION_PLAN.md) - getSortNo() carries the source's own
 * ordering for that case; it is not a synthetic value.
 */
interface ItemSpecificationValueInterface
{
    public function getId(): int;

    public function getItemId(): int;

    public function getSpecificationId(): int;

    /**
     * dtb_specification_class.id - the chosen option.
     */
    public function getSpecificationClassId(): int;

    public function getSortNo(): int;

    public function isSelectable(): bool;
}
