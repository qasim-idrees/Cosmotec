<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Test\Unit\Model\Validator;

use Cosmotec\EccubeMigration\Model\Validator\ValidationResult;
use PHPUnit\Framework\TestCase;

class ValidationResultTest extends TestCase
{
    public function testSuccessIsValidAndHasNoErrors(): void
    {
        $result = ValidationResult::success();

        $this->assertTrue($result->isValid());
        $this->assertSame([], $result->getErrors());
        $this->assertSame('', $result->getErrorsAsString());
    }

    public function testFailureIsInvalidAndCarriesErrors(): void
    {
        $result = ValidationResult::failure(['first error', 'second error']);

        $this->assertFalse($result->isValid());
        $this->assertSame(['first error', 'second error'], $result->getErrors());
        $this->assertSame('first error; second error', $result->getErrorsAsString());
    }
}
