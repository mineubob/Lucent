<?php

namespace Tests\Unit\Validation\Constraints;

use Lucent\Validation\Constraints\Boolean;
use PHPUnit\Framework\TestCase;
use Tests\Support\Concerns\BuildsValidationRequests;

class BooleanTest extends TestCase
{
    use BuildsValidationRequests;

    private function validate(mixed $value, Boolean $constraint = new Boolean()): \Lucent\Validation\Result
    {
        return $this->validateValue($value, $constraint);
    }

    // ─── real booleans pass ────────────────────────────────────────────────

    public function test_true_passes(): void
    {
        $this->assertFalse($this->validate(true)->hasErrors());
    }

    public function test_false_passes(): void
    {
        $this->assertFalse($this->validate(false)->hasErrors());
    }

    // ─── string representations normalize ──────────────────────────────────

    public function test_true_string_normalizes_to_true(): void
    {
        $result = $this->validate('true');

        $this->assertFalse($result->hasErrors());
        $this->assertTrue($result->value(''));
    }

    public function test_false_string_normalizes_to_false(): void
    {
        $result = $this->validate('false');

        $this->assertFalse($result->hasErrors());
        $this->assertFalse($result->value(''));
    }

    public function test_one_string_normalizes_to_true(): void
    {
        $result = $this->validate('1');

        $this->assertFalse($result->hasErrors());
        $this->assertTrue($result->value(''));
    }

    public function test_zero_string_normalizes_to_false(): void
    {
        $result = $this->validate('0');

        $this->assertFalse($result->hasErrors());
        $this->assertFalse($result->value(''));
    }

    public function test_one_integer_normalizes_to_true(): void
    {
        $result = $this->validate(1);

        $this->assertFalse($result->hasErrors());
        $this->assertTrue($result->value(''));
    }

    public function test_zero_integer_normalizes_to_false(): void
    {
        $result = $this->validate(0);

        $this->assertFalse($result->hasErrors());
        $this->assertFalse($result->value(''));
    }

    // ─── case-insensitive word forms ───────────────────────────────────────

    public function test_uppercase_true_normalizes_to_true(): void
    {
        $result = $this->validate('TRUE');

        $this->assertFalse($result->hasErrors());
        $this->assertTrue($result->value(''));
    }

    public function test_mixed_case_false_normalizes_to_false(): void
    {
        $result = $this->validate('False');

        $this->assertFalse($result->hasErrors());
        $this->assertFalse($result->value(''));
    }

    // ─── invalid values fail ───────────────────────────────────────────────

    public function test_arbitrary_string_fails(): void
    {
        $this->assertTrue($this->validate('yes')->hasErrors());
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
        $result = $this->validateField('field', ['field' => 'yes'], new Boolean());

        $this->assertSame(
            ['The field must be a boolean.'],
            $result->errors()['field'],
        );
    }
}