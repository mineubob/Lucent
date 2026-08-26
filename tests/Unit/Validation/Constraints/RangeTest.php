<?php

namespace Tests\Unit\Validation\Constraints;

use Lucent\Validation\Constraints\Range;
use PHPUnit\Framework\TestCase;
use Tests\Support\Concerns\BuildsValidationRequests;

class RangeTest extends TestCase
{
    use BuildsValidationRequests;

    private function validate(array $body, Range $constraint): \Lucent\Validation\Result
    {
        return $this->validateField('value', $body, $constraint);
    }

    // ─── min-only ──────────────────────────────────────────────────────────

    public function test_min_only_passes_above_min(): void
    {
        $result = $this->validate(['value' => 10], new Range(min: 5));

        $this->assertFalse($result->hasErrors());
    }

    public function test_min_only_fails_below_min(): void
    {
        $result = $this->validate(['value' => 4], new Range(min: 5));

        $this->assertTrue($result->hasErrors());
    }

    public function test_min_boundary_is_inclusive(): void
    {
        $result = $this->validate(['value' => 5], new Range(min: 5));

        $this->assertFalse($result->hasErrors());
    }

    // ─── max-only ──────────────────────────────────────────────────────────

    public function test_max_only_passes_below_max(): void
    {
        $result = $this->validate(['value' => 5], new Range(max: 10));

        $this->assertFalse($result->hasErrors());
    }

    public function test_max_only_fails_above_max(): void
    {
        $result = $this->validate(['value' => 11], new Range(max: 10));

        $this->assertTrue($result->hasErrors());
    }

    public function test_max_boundary_is_inclusive(): void
    {
        $result = $this->validate(['value' => 10], new Range(max: 10));

        $this->assertFalse($result->hasErrors());
    }

    // ─── both bounds ───────────────────────────────────────────────────────

    public function test_both_bounds_pass_within_range(): void
    {
        $result = $this->validate(['value' => 7], new Range(min: 5, max: 10));

        $this->assertFalse($result->hasErrors());
    }

    public function test_both_bounds_fail_outside_range(): void
    {
        $this->assertTrue($this->validate(['value' => 4], new Range(min: 5, max: 10))->hasErrors());
        $this->assertTrue($this->validate(['value' => 11], new Range(min: 5, max: 10))->hasErrors());
    }

    // ─── negative and float bounds ─────────────────────────────────────────

    public function test_negative_bounds(): void
    {
        $this->assertFalse($this->validate(['value' => -5], new Range(min: -10, max: 10))->hasErrors());
        $this->assertTrue($this->validate(['value' => -11], new Range(min: -10, max: 10))->hasErrors());
        $this->assertFalse($this->validate(['value' => 0], new Range(min: -10, max: 10))->hasErrors());
    }

    public function test_float_bounds_and_values(): void
    {
        $this->assertFalse($this->validate(['value' => 50.5], new Range(min: 0.5, max: 99.99))->hasErrors());
        $this->assertTrue($this->validate(['value' => 0.4], new Range(min: 0.5, max: 99.99))->hasErrors());
    }

    public function test_inf_bounds_act_as_unbounded(): void
    {
        $this->assertFalse($this->validate(['value' => 1000000], new Range(max: INF))->hasErrors());
        $this->assertFalse($this->validate(['value' => -1000000], new Range(min: -INF))->hasErrors());
    }

    // ─── string normalization ──────────────────────────────────────────────

    public function test_numeric_string_normalized_to_int(): void
    {
        $result = $this->validate(['value' => '10'], new Range(min: 5));

        $this->assertFalse($result->hasErrors());
        $this->assertSame(10, $result->value('value'));
    }

    public function test_numeric_string_normalized_to_float(): void
    {
        $result = $this->validate(['value' => '3.14'], new Range(min: 0, max: 10));

        $this->assertFalse($result->hasErrors());
        $this->assertSame(3.14, $result->value('value'));
    }

    public function test_out_of_range_value_is_not_normalized(): void
    {
        // Regression test for AUD-COR-003: an out-of-range value must not be
        // normalized (cast to a number) in the result. The raw value is still
        // seeded, but it must remain the raw string, not the cast number, so
        // a consumer that reads values without checking hasErrors() never
        // sees a value that passed the bounds check.
        $result = $this->validate(['value' => '11'], new Range(min: 5, max: 10));

        $this->assertTrue($result->hasErrors());
        $this->assertSame('11', $result->value('value'));
    }

    // ─── invalid values ────────────────────────────────────────────────────

    public function test_empty_string_fails(): void
    {
        $result = $this->validate(['value' => ''], new Range(min: 5));

        $this->assertTrue($result->hasErrors());
    }

    public function test_non_numeric_values_fail(): void
    {
        foreach (['abc', ['5'], null, true] as $value) {
            $result = $this->validate(['value' => $value], new Range(min: 5));
            $this->assertTrue($result->hasErrors(), "Expected fail for " . var_export($value, true));
        }
    }

    // ─── constructor ───────────────────────────────────────────────────────

    public function test_constructor_throws_when_both_bounds_null(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Range();
    }

    // ─── messages ──────────────────────────────────────────────────────────

    public function test_min_only_message(): void
    {
        $result = $this->validate(['value' => 4], new Range(min: 5));

        $this->assertSame(['The value field must be at least 5.'], $result->errors()['value']);
    }

    public function test_max_only_message(): void
    {
        $result = $this->validate(['value' => 11], new Range(max: 10));

        $this->assertSame(['The value field must be at most 10.'], $result->errors()['value']);
    }

    public function test_both_bounds_message(): void
    {
        $result = $this->validate(['value' => 4], new Range(min: 5, max: 10));

        $this->assertSame(['The value field must be between 5 and 10.'], $result->errors()['value']);
    }
}