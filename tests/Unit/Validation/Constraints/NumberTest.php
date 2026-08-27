<?php

namespace Tests\Unit\Validation\Constraints;

use Lucent\Validation\Constraints\Number;
use PHPUnit\Framework\TestCase;
use Tests\Support\Concerns\BuildsValidationRequests;

class NumberTest extends TestCase
{
    use BuildsValidationRequests;

    private function validate(mixed $value, Number $constraint = new Number()): \Lucent\Validation\Result
    {
        return $this->validateValue($value, $constraint);
    }

    // ─── numbers pass ──────────────────────────────────────────────────────

    public function test_integer_passes(): void
    {
        $this->assertFalse($this->validate(42)->hasErrors());
    }

    public function test_float_passes(): void
    {
        $this->assertFalse($this->validate(3.14)->hasErrors());
    }

    public function test_zero_passes(): void
    {
        $this->assertFalse($this->validate(0)->hasErrors());
    }

    // ─── non-numbers fail ──────────────────────────────────────────────────

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

    public function test_array_fails(): void
    {
        $this->assertTrue($this->validate([1])->hasErrors());
    }

    // ─── message ───────────────────────────────────────────────────────────

    public function test_error_message(): void
    {
        $result = $this->validateField('field', ['field' => '42'], new Number());

        $this->assertSame(
            ['The field must be a number.'],
            $result->errors()['field'],
        );
    }
}