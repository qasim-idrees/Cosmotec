<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Test\Unit\Model\DTO;

use Cosmotec\EccubeMigration\Api\Data\SpecificationInterface;
use Cosmotec\EccubeMigration\Model\DTO\Specification;
use PHPUnit\Framework\TestCase;

class SpecificationTest extends TestCase
{
    /**
     * Real source record: specification 27 ("D"), used at both scopes,
     * 455 options — the archetypal CREATE case.
     */
    public function testUsedSpecificationWithOptionsIsCreate(): void
    {
        $spec = $this->make(id: 27, nameEn: 'D', optionCount: 455, usedItem: true, usedProduct: true);

        $this->assertSame(SpecificationInterface::CLASSIFICATION_CREATE, $spec->getClassification());
        $this->assertNotSame('', $spec->getClassificationReason());
    }

    /**
     * Real source record: specification 146 ("Tightening torque") has 10
     * options but no item references it.
     */
    public function testUnusedSpecificationWithOptionsIsSkipUnused(): void
    {
        $spec = $this->make(id: 146, nameEn: 'Tightening torque', optionCount: 10, usedItem: false, usedProduct: false);

        $this->assertSame(SpecificationInterface::CLASSIFICATION_SKIP_UNUSED, $spec->getClassification());
        $this->assertStringContainsString('not referenced', $spec->getClassificationReason());
    }

    /**
     * Real source record: specification 273 ("ZVF") — no options, no
     * references, and not a set-specification trigger.
     */
    public function testUnusedSpecificationWithoutOptionsIsSkipInvalid(): void
    {
        $spec = $this->make(id: 273, nameEn: 'ZVF', optionCount: 0, usedItem: false, usedProduct: false);

        $this->assertSame(SpecificationInterface::CLASSIFICATION_SKIP_INVALID, $spec->getClassification());
    }

    /**
     * Real source records 1-8 and 334-339 are SetSpecificationRule
     * triggers: zero options BY DESIGN, because they control which other
     * specifications apply rather than holding a value. These must never
     * be silently discarded as "invalid" — they carry real business
     * meaning (e.g. id 2 = "Flange ICF (2 pieces)" implies specification
     * 9 is recorded twice, once per physical connector).
     */
    public function testSetSpecificationTriggersAreNeedsReview(): void
    {
        foreach ([1, 2, 3, 4, 5, 6, 7, 8, 334, 335, 336, 337, 338, 339] as $id) {
            $spec = $this->make(id: $id, nameEn: 'Flange trigger', optionCount: 0, usedItem: false, usedProduct: false);

            $this->assertSame(
                SpecificationInterface::CLASSIFICATION_NEEDS_REVIEW,
                $spec->getClassification(),
                sprintf('Specification %d is a SetSpecificationRule trigger and must be flagged for review', $id)
            );
            $this->assertStringContainsString('Set-specification trigger', $spec->getClassificationReason());
        }
    }

    public function testReferencedSpecificationWithoutOptionsIsNeedsReview(): void
    {
        $spec = $this->make(id: 9999, nameEn: 'Referenced but optionless', optionCount: 0, usedItem: true, usedProduct: false);

        $this->assertSame(SpecificationInterface::CLASSIFICATION_NEEDS_REVIEW, $spec->getClassification());
    }

    /**
     * The ecs_{normalized_name}_{id} convention: the trailing id is the
     * permanent identity, so two DTOs built with the same id always share
     * that suffix even though their name fragments differ - this is what
     * guarantees an id lookup is always possible. The name fragment itself
     * is only a readability aid computed from the CURRENT name; whether an
     * already-created Magento attribute actually gets renamed when the
     * source name changes is decided by AttributeImporter (it prefers the
     * stored map row's attribute_code over recomputing), not by this DTO.
     */
    public function testAttributeCodeIdSuffixIsStableAcrossLabelChanges(): void
    {
        $a = $this->make(id: 27, nameEn: 'D', optionCount: 455, usedItem: true, usedProduct: true);
        $b = $this->make(id: 27, nameEn: 'Completely Different Label', optionCount: 455, usedItem: true, usedProduct: true);

        $this->assertSame('ecs_d_27', $a->getMagentoAttributeCode());
        $this->assertStringEndsWith('_27', $b->getMagentoAttributeCode());
        $this->assertNotSame($a->getMagentoAttributeCode(), $b->getMagentoAttributeCode());
    }

    public function testEnglishFirstLabelSelection(): void
    {
        $spec = $this->make(id: 9, nameEn: 'ICF', optionCount: 27, usedItem: true, usedProduct: true, name: 'ICFフランジ');

        $this->assertSame('ICF', $spec->getLabel());
    }

    public function testFallsBackToJapaneseOnlyWhenEnglishIsEmpty(): void
    {
        $spec = $this->make(id: 9, nameEn: '   ', optionCount: 27, usedItem: true, usedProduct: true, name: 'ICFフランジ');

        $this->assertSame('ICFフランジ', $spec->getLabel());
    }

    public function testScopeFlagsAreIndependent(): void
    {
        // 80 real specifications are used at BOTH scopes — scope is a
        // property of the (item, specification) pair, not the spec alone.
        $both = $this->make(id: 9, nameEn: 'ICF', optionCount: 27, usedItem: true, usedProduct: true);
        $this->assertTrue($both->isUsedAtItemScope());
        $this->assertTrue($both->isUsedAtProductScope());

        $productOnly = $this->make(id: 100, nameEn: 'X', optionCount: 3, usedItem: false, usedProduct: true);
        $this->assertFalse($productOnly->isUsedAtItemScope());
        $this->assertTrue($productOnly->isUsedAtProductScope());
    }

    private function make(
        int $id,
        string $nameEn,
        int $optionCount,
        bool $usedItem,
        bool $usedProduct,
        string $name = 'JA',
        int $selectableCount = 0
    ): Specification {
        return new Specification($id, null, $name, $nameEn, 0, $optionCount, $usedItem, $usedProduct, $selectableCount);
    }
}
