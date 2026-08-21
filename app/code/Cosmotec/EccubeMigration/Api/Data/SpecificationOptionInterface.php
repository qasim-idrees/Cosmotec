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
 * One dtb_specification_class row — a Magento attribute option.
 *
 * Ordering note (§30): sort_no is EC-CUBE's deliberate custom ordering and
 * must be preserved. Options must never be re-sorted alphabetically or by
 * numeric value, even where the labels look numeric (34, 70, 114...).
 */
interface SpecificationOptionInterface
{
    public function getId(): int;

    public function getSpecificationId(): int;

    public function getName(): string;

    public function getNameEn(): string;

    /**
     * English-first (§4). Falls back to Japanese only when the English
     * label is genuinely empty — which does not occur in the current
     * dataset (7,364/7,364 have English names).
     */
    public function getLabel(): string;

    public function getSortNo(): int;
}
