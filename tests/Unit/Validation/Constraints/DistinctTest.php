<?php

namespace Tests\Unit\Validation\Constraints;

use Lucent\Validation\Constraints\Distinct;
use PHPUnit\Framework\TestCase;
use Tests\Support\Concerns\BuildsValidationRequests;

class DistinctTest extends TestCase
{
    use BuildsValidationRequests;

    private function validate(mixed $value, Distinct $constraint = new Distinct()): \Lucent\Validation\Result
    {
        return $this->validateValue($value, $constraint);
    }

    // ─── unique arrays pass ────────────────────────────────────────────────

    public function test_unique_list_passes(): void
    {
        $this->assertFalse($this->validate(['a', 'b', 'c'])->hasErrors());
    }

    public function test_single_element_passes(): void
    {
        $this->assertFalse($this->validate(['a'])->hasErrors());
    }

    public function test_empty_array_passes(): void
    {
        $this->assertFalse($this->validate([])->hasErrors());
    }

    public function test_unique_associative_array_passes(): void
    {
        $this->assertFalse($this->validate(['x' => 1, 'y' => 2])->hasErrors());
    }

    // ─── duplicate arrays fail ─────────────────────────────────────────────

    public function test_duplicate_strings_fail(): void
    {
        $this->assertTrue($this->validate(['a', 'b', 'a'])->hasErrors());
    }

    public function test_duplicate_integers_fail(): void
    {
        $this->assertTrue($this->validate([1, 2, 1])->hasErrors());
    }

    // ─── strict comparison ─────────────────────────────────────────────────

    public function test_strict_comparison_treats_int_and_string_as_distinct(): void
    {
        $this->assertFalse($this->validate([1, '1'])->hasErrors());
    }

    // ─── non-arrays fail ───────────────────────────────────────────────────

    public function test_string_fails(): void
    {
        $this->assertTrue($this->validate('abc')->hasErrors());
    }

    public function test_null_fails(): void
    {
        $this->assertTrue($this->validate(null)->hasErrors());
    }

    // ─── message ───────────────────────────────────────────────────────────

    public function test_error_message(): void
    {
        $result = $this->validateField('field', ['field' => ['a', 'a']], new Distinct());

        $this->assertSame(
            ['The field must not contain duplicate values.'],
            $result->errors()['field'],
        );
    }
}