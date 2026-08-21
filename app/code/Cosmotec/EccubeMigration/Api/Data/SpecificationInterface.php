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
 * One dtb_specification row enriched with the usage statistics needed to
 * classify it for Magento attribute creation. Read-only analysis DTO —
 * produced by SpecificationRepository, consumed by the analyze:* CLI
 * commands. Creating actual Magento attributes is a separate, later
 * concern that must not reuse this as a write model.
 */
interface SpecificationInterface
{
    public const CLASSIFICATION_CREATE = 'CREATE';
    public const CLASSIFICATION_SKIP_UNUSED = 'SKIP_UNUSED';
    public const CLASSIFICATION_SKIP_INVALID = 'SKIP_INVALID';
    public const CLASSIFICATION_NEEDS_REVIEW = 'NEEDS_REVIEW';

    public function getId(): int;

    public function getSpecificationGroupId(): ?int;

    public function getName(): string;

    public function getNameEn(): string;

    /**
     * English-first per the confirmed migration rule; falls back to
     * Japanese only when the English name is empty (which, in the current
     * dataset, never happens — 360/360 specifications have English names).
     */
    public function getLabel(): string;

    public function getSortNo(): int;

    /**
     * Number of dtb_specification_class rows (Magento attribute options).
     */
    public function getOptionCount(): int;

    /**
     * True if any dtb_item_specification row with type=1 (TYPE_ITEM)
     * references this specification.
     */
    public function isUsedAtItemScope(): bool;

    /**
     * True if any dtb_item_specification row with type=2 (TYPE_PRODUCT)
     * references this specification.
     */
    public function isUsedAtProductScope(): bool;

    /**
     * Count of dtb_item_specification rows with selectable=1 — the
     * source's per-(item,specification) filterability signal.
     */
    public function getSelectableCount(): int;

    /**
     * Deterministic Magento attribute code: eccube_spec_{id}. Stable
     * across runs and independent of labels by design.
     */
    public function getMagentoAttributeCode(): string;

    /**
     * One of the CLASSIFICATION_* constants.
     */
    public function getClassification(): string;

    /**
     * Human-readable justification for the classification — never empty
     * for a skipped specification, so nothing is dropped without a
     * recorded reason.
     */
    public function getClassificationReason(): string;
}
