<?php

namespace Tests\Unit\Validation\Constraints;

use Lucent\Validation\Combinators\Shape;
use Lucent\Validation\Constraints\SameAs;
use Lucent\Validation\Validator;
use PHPUnit\Framework\TestCase;
use Tests\Support\Concerns\BuildsValidationRequests;

class SameAsTest extends TestCase
{
    use BuildsValidationRequests;

    // ─── matching / mismatching ────────────────────────────────────────────

    public function test_matching_values_pass(): void
    {
        $validator = new Validator([
            'password' => new SameAs('password_confirmation'),
        ]);

        $result = $validator->validate([
            'password' => 'secret',
            'password_confirmation' => 'secret',
        ]);

        $this->assertFalse($result->hasErrors());
    }

    public function test_mismatching_values_fail(): void
    {
        $validator = new Validator([
            'password' => new SameAs('password_confirmation'),
        ]);

        $result = $validator->validate([
            'password' => 'secret',
            'password_confirmation' => 'different',
        ]);

        $this->assertTrue($result->hasErrors());
    }

    // ─── strict equality ───────────────────────────────────────────────────

    public function test_type_mismatch_fails(): void
    {
        $validator = new Validator([
            'a' => new SameAs('b'),
        ]);

        $this->assertTrue($validator->validate(['a' => 0, 'b' => '0'])->hasErrors());
        $this->assertTrue($validator->validate(['a' => true, 'b' => '1'])->hasErrors());
    }

    // ─── sibling resolution ────────────────────────────────────────────────

    public function test_sibling_resolution_nested_in_shape(): void
    {
        $validator = new Validator([
            'user' => Shape::object([
                'password' => new SameAs('password_confirmation'),
            ]),
        ]);

        $result = $validator->validate([
            'user' => [
                'password' => 'secret',
                'password_confirmation' => 'secret',
            ],
        ]);

        $this->assertFalse($result->hasErrors());
    }

    public function test_sibling_mismatch_nested_in_shape_fails(): void
    {
        $validator = new Validator([
            'user' => Shape::object([
                'password' => new SameAs('password_confirmation'),
            ]),
        ]);

        $result = $validator->validate([
            'user' => [
                'password' => 'secret',
                'password_confirmation' => 'different',
            ],
        ]);

        $this->assertTrue($result->hasErrors());
        $this->assertArrayHasKey('user.password', $result->errors());
    }

    /**
     * BUG-REVEALING TEST: SameAs with a dotted field name. resolveSibling()
     * prepends the parent path to any field containing a dot, producing
     * "user.user.password_confirmation" instead of "user.password_confirmation",
     * so the comparison fails.
     */
    public function test_dotted_field_name(): void
    {
        $validator = new Validator([
            'user' => Shape::object([
                'password' => new SameAs('user.password_confirmation'),
            ]),
        ]);

        $result = $validator->validate([
            'user' => [
                'password' => 'secret',
                'password_confirmation' => 'secret',
            ],
        ]);

        $this->assertFalse($result->hasErrors());
    }

    // ─── absent other field ────────────────────────────────────────────────

    public function test_absent_other_field_fails_unless_value_is_null(): void
    {
        $validator = new Validator([
            'a' => new SameAs('b'),
        ]);

        // b absent -> valueOf returns null; 'x' !== null -> fails.
        $this->assertTrue($validator->validate(['a' => 'x'])->hasErrors());
        // a null and b absent -> null === null -> passes.
        $this->assertFalse($validator->validate(['a' => null])->hasErrors());
    }

    // ─── empty string ──────────────────────────────────────────────────────

    public function test_empty_string_matches_another_empty_string(): void
    {
        $validator = new Validator([
            'a' => new SameAs('b'),
        ]);

        $this->assertFalse($validator->validate(['a' => '', 'b' => ''])->hasErrors());
    }

    public function test_empty_string_mismatch_fails(): void
    {
        $validator = new Validator([
            'a' => new SameAs('b'),
        ]);

        $this->assertTrue($validator->validate(['a' => '', 'b' => 'x'])->hasErrors());
    }

    // ─── message ───────────────────────────────────────────────────────────

    public function test_error_message(): void
    {
        $validator = new Validator([
            'password' => new SameAs('password_confirmation'),
        ]);

        $result = $validator->validate([
            'password' => 'secret',
            'password_confirmation' => 'different',
        ]);

        $this->assertSame(
            ['password must match the value of password_confirmation'],
            $result->errors()['password'],
        );
    }
}