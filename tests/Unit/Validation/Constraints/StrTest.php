<?php

namespace Tests\Unit\Validation\Constraints;

use Lucent\Validation\Constraints\Str;
use PHPUnit\Framework\TestCase;
use Tests\Support\Concerns\BuildsValidationRequests;

class StrTest extends TestCase
{
    use BuildsValidationRequests;

    private function validate(mixed $value, Str $constraint = new Str()): \Lucent\Validation\Result
    {
        return $this->validateValue($value, $constraint);
    }

    // ─── strings pass ──────────────────────────────────────────────────────

    public function test_string_passes(): void
    {
        $this->assertFalse($this->validate('hello')->hasErrors());
    }

    public function test_empty_string_passes(): void
    {
        $this->assertFalse($this->validate('')->hasErrors());
    }

    // ─── non-strings fail ──────────────────────────────────────────────────

    public function test_integer_fails(): void
    {
        $this->assertTrue($this->validate(42)->hasErrors());
    }

    public function test_float_fails(): void
    {
        $this->assertTrue($this->validate(3.14)->hasErrors());
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
        $this->assertTrue($this->validate(['a', 'b'])->hasErrors());
    }

    // ─── message ───────────────────────────────────────────────────────────

    public function test_error_message(): void
    {
        $result = $this->validateField('field', ['field' => 42], new Str());

        $this->assertSame(
            ['The field must be a string.'],
            $result->errors()['field'],
        );
    }
}