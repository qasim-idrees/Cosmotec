<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Test\Unit\Model\DTO;

use Cosmotec\EccubeMigration\Model\DTO\SpecificationOption;
use PHPUnit\Framework\TestCase;

class SpecificationOptionTest extends TestCase
{
    /**
     * Real source rows: specification 9 (ICF) options "34", "54", "70"
     * carry sort_no 0, 1, 3 — deliberately non-contiguous. The migration
     * must preserve that ordering rather than renumbering or sorting
     * numerically (§30).
     */
    public function testSourceSortOrderIsPreservedVerbatim(): void
    {
        $a = new SpecificationOption(1, 9, '34', '34', 0);
        $b = new SpecificationOption(2, 9, '54', '54', 1);
        $c = new SpecificationOption(3, 9, '70', '70', 3);

        $this->assertSame(0, $a->getSortNo());
        $this->assertSame(1, $b->getSortNo());
        $this->assertSame(3, $c->getSortNo(), 'Gaps in source sort_no must not be normalised away');
    }

    public function testEnglishFirstLabel(): void
    {
        $option = new SpecificationOption(1, 9, 'サニタリー', 'sanitary', 0);

        $this->assertSame('sanitary', $option->getLabel());
    }

    public function testFallsBackToJapaneseOnlyWhenEnglishEmpty(): void
    {
        $option = new SpecificationOption(1, 9, 'サニタリー', '   ', 0);

        $this->assertSame('サニタリー', $option->getLabel());
    }

    /**
     * Numeric-looking labels are still labels, never cast to numbers —
     * "34" and "38.1" must survive as written.
     */
    public function testNumericLookingLabelsStayStrings(): void
    {
        $option = new SpecificationOption(4, 27, '38.1', '38.1', 2);

        $this->assertSame('38.1', $option->getLabel());
        $this->assertIsString($option->getLabel());
    }

    public function testCarriesOwningSpecificationId(): void
    {
        $option = new SpecificationOption(1, 9, '34', '34', 0);

        $this->assertSame(9, $option->getSpecificationId());
        $this->assertSame(1, $option->getId());
    }
}
