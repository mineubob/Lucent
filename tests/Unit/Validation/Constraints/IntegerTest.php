<?php

namespace Tests\Unit\Validation\Constraints;

use Lucent\Validation\Constraints\Integer;
use PHPUnit\Framework\TestCase;
use Tests\Support\Concerns\BuildsValidationRequests;

class IntegerTest extends TestCase
{
    use BuildsValidationRequests;

    private function validate(mixed $value, Integer $constraint = new Integer()): \Lucent\Validation\Result
    {
        return $this->validateValue($value, $constraint);
    }

    // ─── integers pass ─────────────────────────────────────────────────────

    public function test_integer_passes(): void
    {
        $this->assertFalse($this->validate(42)->hasErrors());
    }

    public function test_zero_passes(): void
    {
        $this->assertFalse($this->validate(0)->hasErrors());
    }

    public function test_negative_integer_passes(): void
    {
        $this->assertFalse($this->validate(-5)->hasErrors());
    }

    // ─── non-integers fail ─────────────────────────────────────────────────

    public function test_float_fails(): void
    {
        $this->assertTrue($this->validate(3.14)->hasErrors());
    }

    public function test_numeric_string_fails(): void
    {
        $this->assertTrue($this->validate('42')->hasErrors());
    }

    public function test_string_fails(): void
    {
        $this->assertTrue($this->validate('abc')->hasErrors());
    }

    public function test_boolean_fails(): void
    {
        $this->assertTrue($this->validate(true)->hasErrors());
    }

    public function test_null_fails(): void
    {
        $this->assertTrue($this->validate(null)->hasErrors());
    }

    // ─── message ───────────────────────────────────────────────────────────

    public function test_error_message(): void
    {
        $result = $this->validateField('field', ['field' => '42'], new Integer());

        $this->assertSame(
            ['The field must be an integer.'],
            $result->errors()['field'],
        );
    }
}