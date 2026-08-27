<?php

namespace Tests\Unit\Validation\Constraints;

use Lucent\Validation\Constraints\Matches;
use PHPUnit\Framework\TestCase;
use Tests\Support\Concerns\BuildsValidationRequests;

class MatchesTest extends TestCase
{
    use BuildsValidationRequests;

    private function validate(array $body, Matches $constraint): \Lucent\Validation\Result
    {
        return $this->validateField('value', $body, $constraint);
    }

    // ─── custom pattern ────────────────────────────────────────────────────

    public function test_custom_pattern_passes(): void
    {
        $result = $this->validate(['value' => 'abc123'], new Matches('/^[a-z0-9]+$/'));

        $this->assertFalse($result->hasErrors());
    }

    public function test_custom_pattern_fails(): void
    {
        $result = $this->validate(['value' => 'ABC!'], new Matches('/^[a-z0-9]+$/'));

        $this->assertTrue($result->hasErrors());
    }

    public function test_unanchored_pattern_matches_substring(): void
    {
        $result = $this->validate(['value' => 'xabcx'], new Matches('/abc/'));

        $this->assertFalse($result->hasErrors());
    }

    public function test_anchored_pattern_rejects_substring(): void
    {
        $result = $this->validate(['value' => 'xabcx'], new Matches('/^abc$/'));

        $this->assertTrue($result->hasErrors());
    }

    public function test_flags_passed_through_to_preg_match(): void
    {
        // PREG_OFFSET_CAPTURE should not change the boolean match result.
        $result = $this->validate(['value' => 'abc'], new Matches('/^abc$/', PREG_OFFSET_CAPTURE));

        $this->assertFalse($result->hasErrors());
    }

    // ─── mobile() ──────────────────────────────────────────────────────────

    public function test_mobile_valid(): void
    {
        foreach (['+61412345678', '61412345678'] as $value) {
            $result = $this->validate(['value' => $value], Matches::mobile());
            $this->assertFalse($result->hasErrors(), "Expected pass for $value");
        }
    }

    public function test_mobile_invalid(): void
    {
        // '123' is actually valid (1-9 then 2 digits = 3 digits). Invalid cases:
        // '0' (leading zero not allowed), 'abc' (non-numeric), '+1' (too short).
        foreach (['0', 'abc', '+1', '12345678901234567890'] as $value) {
            $result = $this->validate(['value' => $value], Matches::mobile());
            $this->assertTrue($result->hasErrors(), "Expected fail for $value");
        }
    }

    public function test_mobile_message(): void
    {
        $result = $this->validate(['value' => 'abc'], Matches::mobile());

        $this->assertSame(
            ['Phone number must be in a valid international format.'],
            $result->errors()['value'],
        );
    }

    // ─── password() ────────────────────────────────────────────────────────

    public function test_password_valid(): void
    {
        $result = $this->validate(['value' => 'Password123'], Matches::password());

        $this->assertFalse($result->hasErrors());
    }

    public function test_password_missing_uppercase_fails(): void
    {
        $result = $this->validate(['value' => 'password123'], Matches::password());

        $this->assertTrue($result->hasErrors());
    }

    public function test_password_missing_lowercase_fails(): void
    {
        $result = $this->validate(['value' => 'PASSWORD123'], Matches::password());

        $this->assertTrue($result->hasErrors());
    }

    public function test_password_too_short_fails(): void
    {
        $result = $this->validate(['value' => 'Pa1'], Matches::password());

        $this->assertTrue($result->hasErrors());
    }

    public function test_password_message(): void
    {
        $result = $this->validate(['value' => 'short'], Matches::password());

        $this->assertSame(
            ['Password must contain at least one lowercase letter, one uppercase letter, and be at least 8 characters long.'],
            $result->errors()['value'],
        );
    }

    // ─── alpha() ───────────────────────────────────────────────────────────

    public function test_alpha_valid(): void
    {
        foreach (['abc', 'ABC', 'AbC'] as $value) {
            $result = $this->validate(['value' => $value], Matches::alpha());
            $this->assertFalse($result->hasErrors(), "Expected pass for $value");
        }
    }

    public function test_alpha_invalid(): void
    {
        foreach (['abc123', '', 'abc_def'] as $value) {
            $result = $this->validate(['value' => $value], Matches::alpha());
            $this->assertTrue($result->hasErrors(), "Expected fail for $value");
        }
    }

    public function test_alpha_message(): void
    {
        $result = $this->validate(['value' => 'abc123'], Matches::alpha());

        $this->assertSame(['Must contain only letters.'], $result->errors()['value']);
    }

    // ─── alphanumeric() ────────────────────────────────────────────────────

    public function test_alphanumeric_valid(): void
    {
        foreach (['abc123', 'abc', '123'] as $value) {
            $result = $this->validate(['value' => $value], Matches::alphanumeric());
            $this->assertFalse($result->hasErrors(), "Expected pass for $value");
        }
    }

    public function test_alphanumeric_invalid(): void
    {
        foreach (['abc_123', 'abc-123', ''] as $value) {
            $result = $this->validate(['value' => $value], Matches::alphanumeric());
            $this->assertTrue($result->hasErrors(), "Expected fail for $value");
        }
    }

    public function test_alphanumeric_message(): void
    {
        $result = $this->validate(['value' => 'abc_123'], Matches::alphanumeric());

        $this->assertSame(
            ['Must contain only letters and numbers.'],
            $result->errors()['value'],
        );
    }

    // ─── hexColor() ────────────────────────────────────────────────────────

    public function test_hex_color_valid(): void
    {
        foreach (['#FFFFFF', 'FFFFFF', '#fff', 'abc'] as $value) {
            $result = $this->validate(['value' => $value], Matches::hexColor());
            $this->assertFalse($result->hasErrors(), "Expected pass for $value");
        }
    }

    public function test_hex_color_invalid(): void
    {
        foreach (['GGGGGG', '#GGGGGG', '12345', '#12345'] as $value) {
            $result = $this->validate(['value' => $value], Matches::hexColor());
            $this->assertTrue($result->hasErrors(), "Expected fail for $value");
        }
    }

    public function test_hex_color_message(): void
    {
        $result = $this->validate(['value' => 'GGGGGG'], Matches::hexColor());

        $this->assertSame(
            ['Must be a valid HEX color code (e.g., #FFF or #FFFFFF).'],
            $result->errors()['value'],
        );
    }

    // ─── default message ───────────────────────────────────────────────────

    public function test_default_message_for_custom_pattern(): void
    {
        $result = $this->validate(['value' => 'ABC!'], new Matches('/^[a-z0-9]+$/'));

        $this->assertSame(
            ['The value field didn\'t match the expected pattern.'],
            $result->errors()['value'],
        );
    }

    // ─── edge cases ────────────────────────────────────────────────────────

    public function test_empty_string_fails(): void
    {
        $result = $this->validate(['value' => ''], new Matches('/^[a-z]+$/'));

        $this->assertTrue($result->hasErrors());
    }

    public function test_non_string_types_fail(): void
    {
        foreach ([123, ['abc'], null, true] as $value) {
            $result = $this->validate(['value' => $value], new Matches('/^[a-z]+$/'));
            $this->assertTrue($result->hasErrors(), "Expected fail for " . var_export($value, true));
        }
    }

    // ─── safePattern() ─────────────────────────────────────────────────────

    public function test_safe_pattern_accepts_safe_regex(): void
    {
        $result = $this->validate(['value' => 'abc123'], Matches::safePattern('/^[a-z0-9]+$/'));

        $this->assertFalse($result->hasErrors());
    }

    public function test_safe_pattern_rejects_nested_quantifier(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Matches::safePattern('/(a+)+$/');
    }

    public function test_safe_pattern_rejects_nested_quantifier_with_brace(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Matches::safePattern('/(a{2,})+$/');
    }

    public function test_safe_pattern_rejects_invalid_pcre(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Matches::safePattern('/[unclosed/');
    }
}