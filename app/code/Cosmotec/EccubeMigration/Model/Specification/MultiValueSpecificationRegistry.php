<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Specification;

/**
 * PENDING BUSINESS DECISION - see BUILD_STATUS.md.
 *
 * DATABASE-CONFIRMED (docs/ATTRIBUTE_MIGRATION_PLAN.md §C, cross-checked
 * live this session): exactly 5 specifications have real, source-confirmed
 * multi-value assignments on dtb_product_specification_class (329 pairs
 * total, 0/329 identical - genuine adapters/reducers with a different
 * flange size at each end, not duplicate data entry).
 *
 * Current standing design (per the continuation prompt's explicit
 * instruction: "keep the current recommended design of Magento multiselect
 * + custom positional table unless later changed") is implemented here and
 * in AttributeImporter/ItemAttributeValueImporter/ProductAttributeValueImporter.
 * This single class is the one place that decision is encoded, so
 * reversing it later (e.g. back to "take first value, plain select") is a
 * one-line change here rather than a hunt across the importer classes.
 *
 * This is explicitly NOT approved for execution - it governs code
 * structure only until the business decision is confirmed.
 */
class MultiValueSpecificationRegistry
{
    /**
     * dtb_specification.id => English name, for reference/logging only.
     */
    private const MULTI_VALUE_SPECIFICATION_IDS = [
        9 => 'ICF',
        10 => 'NW/KF',
        11 => 'VF',
        12 => 'VG',
        27 => 'D',
    ];

    public function isMultiValue(int $specificationId): bool
    {
        return array_key_exists($specificationId, self::MULTI_VALUE_SPECIFICATION_IDS);
    }

    /**
     * @return int[]
     */
    public function getIds(): array
    {
        return array_keys(self::MULTI_VALUE_SPECIFICATION_IDS);
    }
}
