<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Test\Unit\Model\Attribute;

use Cosmotec\EccubeMigration\Model\Attribute\SpecificationAttributeCodeResolver;
use PHPUnit\Framework\TestCase;

class SpecificationAttributeCodeResolverTest extends TestCase
{
    private SpecificationAttributeCodeResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new SpecificationAttributeCodeResolver();
    }

    /**
     * The three examples given for this exact naming convention.
     */
    public function testKnownExamples(): void
    {
        $this->assertSame('ecs_handle_119', $this->resolver->resolve(119, 'handle'));
        $this->assertSame('ecs_clamping_bolt_125', $this->resolver->resolve(125, 'Clamping bolt'));
        $this->assertSame('ecs_recommended_plate_thickness_141', $this->resolver->resolve(141, 'Recommended plate thickness'));
    }

    public function testJapaneseOnlyNameFallsBackToSpecPrefix(): void
    {
        $this->assertSame('ecs_spec_9', $this->resolver->resolve(9, 'サニタリー'));
    }

    public function testEmptyNameFallsBackToSpecPrefix(): void
    {
        $this->assertSame('ecs_spec_42', $this->resolver->resolve(42, ''));
        $this->assertSame('ecs_spec_42', $this->resolver->resolve(42, '   '));
    }

    /**
     * Two specifications that share an identical English name must still
     * resolve to two distinct codes - the id suffix is what guarantees
     * uniqueness, never the name.
     */
    public function testIdenticalNamesProduceDistinctCodesViaIdSuffix(): void
    {
        $a = $this->resolver->resolve(100, 'Flange');
        $b = $this->resolver->resolve(200, 'Flange');

        $this->assertNotSame($a, $b);
        $this->assertStringEndsWith('_100', $a);
        $this->assertStringEndsWith('_200', $b);
    }

    public function testResultStaysWithinMagentosAttributeCodeLength(): void
    {
        $code = $this->resolver->resolve(
            123456,
            'A Very Long Specification Name That Exceeds The Sixty Character Attribute Code Limit By A Lot'
        );

        $this->assertLessThanOrEqual(60, strlen($code));
        $this->assertStringEndsWith('_123456', $code);
        $this->assertMatchesRegularExpression('/^[a-zA-Z]+[a-zA-Z0-9_]*$/', $code);
    }

    public function testResultIsAlwaysValidPerMagentosAttributeCodePattern(): void
    {
        $samples = [
            [1, 'D'],
            [2, 'NW/KF'],
            [3, 'ISO-K'],
            [4, 'Tube / Fitting Size (mm)'],
            [5, '100% Pure Value!'],
            [6, ''],
            [7, 'サニタリー'],
        ];

        foreach ($samples as [$id, $name]) {
            $code = $this->resolver->resolve($id, $name);
            $this->assertMatchesRegularExpression(
                '/^[a-zA-Z]+[a-zA-Z0-9_]*$/',
                $code,
                sprintf('Code "%s" for id=%d name="%s" must satisfy Magento\'s attribute code pattern', $code, $id, $name)
            );
            $this->assertLessThanOrEqual(60, strlen($code));
        }
    }

    public function testResultIsDeterministic(): void
    {
        $this->assertSame(
            $this->resolver->resolve(119, 'handle'),
            $this->resolver->resolve(119, 'handle')
        );
    }

    public function testPrefixConstantMatchesGeneratedCodes(): void
    {
        $this->assertStringStartsWith(SpecificationAttributeCodeResolver::PREFIX, $this->resolver->resolve(119, 'handle'));
        $this->assertStringStartsWith(SpecificationAttributeCodeResolver::PREFIX, $this->resolver->resolve(9, 'サニタリー'));
    }
}
