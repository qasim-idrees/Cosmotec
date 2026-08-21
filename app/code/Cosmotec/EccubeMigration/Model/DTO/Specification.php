<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\DTO;

use Cosmotec\EccubeMigration\Api\Data\SpecificationInterface;

final class Specification implements SpecificationInterface
{
    /**
     * dtb_specification IDs that act as "set specification" triggers in
     * Customize\Model\SetSpecificationRule::SET_SPECIFICATION_RELATION.
     * These legitimately have zero options — they are not value holders,
     * they unlock which OTHER specifications apply (e.g. id 2, "Flange
     * ICF (2 pieces)", maps to [9, 9] meaning specification 9 is recorded
     * twice, once per physical connector). SOURCE-CONFIRMED from
     * app/Customize/Model/SetSpecificationRule.php.
     */
    private const SET_SPECIFICATION_TRIGGER_IDS = [1, 2, 3, 4, 5, 6, 7, 8, 334, 335, 336, 337, 338, 339];

    private readonly string $classification;
    private readonly string $classificationReason;

    public function __construct(
        private readonly int $id,
        private readonly ?int $specificationGroupId,
        private readonly string $name,
        private readonly string $nameEn,
        private readonly int $sortNo,
        private readonly int $optionCount,
        private readonly bool $usedAtItemScope,
        private readonly bool $usedAtProductScope,
        private readonly int $selectableCount
    ) {
        [$this->classification, $this->classificationReason] = $this->classify();
    }

    /**
     * @return array{string, string}
     */
    private function classify(): array
    {
        $used = $this->usedAtItemScope || $this->usedAtProductScope;

        if (in_array($this->id, self::SET_SPECIFICATION_TRIGGER_IDS, true)) {
            return [
                self::CLASSIFICATION_NEEDS_REVIEW,
                'Set-specification trigger (SetSpecificationRule): has no options by design; it controls which other '
                . 'specifications apply rather than holding a value. Not a plain Magento select attribute.',
            ];
        }

        if ($used && $this->optionCount > 0) {
            return [self::CLASSIFICATION_CREATE, 'Referenced by at least one item and has selectable options.'];
        }

        if ($used && $this->optionCount === 0) {
            return [
                self::CLASSIFICATION_NEEDS_REVIEW,
                'Referenced by at least one item but has zero options; cannot become a usable select attribute as-is.',
            ];
        }

        if (!$used && $this->optionCount > 0) {
            return [
                self::CLASSIFICATION_SKIP_UNUSED,
                'Has options but is not referenced by any dtb_item_specification row — no item or product uses it.',
            ];
        }

        return [
            self::CLASSIFICATION_SKIP_INVALID,
            'Neither referenced by any item nor has any options — no usable data.',
        ];
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getSpecificationGroupId(): ?int
    {
        return $this->specificationGroupId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getNameEn(): string
    {
        return $this->nameEn;
    }

    public function getLabel(): string
    {
        $en = trim($this->nameEn);

        return $en !== '' ? $en : trim($this->name);
    }

    public function getSortNo(): int
    {
        return $this->sortNo;
    }

    public function getOptionCount(): int
    {
        return $this->optionCount;
    }

    public function isUsedAtItemScope(): bool
    {
        return $this->usedAtItemScope;
    }

    public function isUsedAtProductScope(): bool
    {
        return $this->usedAtProductScope;
    }

    public function getSelectableCount(): int
    {
        return $this->selectableCount;
    }

    public function getMagentoAttributeCode(): string
    {
        return 'eccube_spec_' . $this->id;
    }

    public function getClassification(): string
    {
        return $this->classification;
    }

    public function getClassificationReason(): string
    {
        return $this->classificationReason;
    }
}
