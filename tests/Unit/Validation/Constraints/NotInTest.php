<?php

namespace Tests\Unit\Validation\Constraints;

use Lucent\Validation\Constraints\NotIn;
use PHPUnit\Framework\TestCase;
use Tests\Support\Concerns\BuildsValidationRequests;

class NotInTest extends TestCase
{
    use BuildsValidationRequests;

    private function validate(mixed $value, NotIn $constraint): \Lucent\Validation\Result
    {
        return $this->validateValue($value, $constraint);
    }

    // ─── allowed values pass ───────────────────────────────────────────────

    public function test_value_not_in_list_passes(): void
    {
        $this->assertFalse($this->validate('user', new NotIn(['admin', 'root']))->hasErrors());
    }

    public function test_empty_forbidden_list_passes(): void
    {
        $this->assertFalse($this->validate('anything', new NotIn([]))->hasErrors());
    }

    // ─── forbidden values fail ─────────────────────────────────────────────

    public function test_forbidden_string_fails(): void
    {
        $this->assertTrue($this->validate('admin', new NotIn(['admin', 'root']))->hasErrors());
    }

    public function test_forbidden_integer_fails(): void
    {
        $this->assertTrue($this->validate(0, new NotIn([0, 1]))->hasErrors());
    }

    // ─── strict comparison ─────────────────────────────────────────────────

    public function test_strict_comparison_treats_int_and_string_as_distinct(): void
    {
        $this->assertFalse($this->validate('1', new NotIn([1]))->hasErrors());
    }

    // ─── message ───────────────────────────────────────────────────────────

    public function test_error_message_lists_forbidden_values(): void
    {
        $result = $this->validateField('field', ['field' => 'admin'], new NotIn(['admin', 'root']));

        $this->assertSame(
            ['The field must not be one of: admin, root.'],
            $result->errors()['field'],
        );
    }

    public function test_error_message_handles_object_without_to_string(): void
    {
        // Regression test: strval() throws on objects without __toString, so
        // the message builder must render them safely.
        $forbidden = new \stdClass();
        $result = $this->validateField('field', ['field' => $forbidden], new NotIn([$forbidden]));

        $this->assertSame(
            ['The field must not be one of: stdClass.'],
            $result->errors()['field'],
        );
    }

    public function test_error_message_handles_boolean_and_null_values(): void
    {
        $result = $this->validateField('field', ['field' => true], new NotIn([true, null]));

        $this->assertSame(
            ['The field must not be one of: true, null.'],
            $result->errors()['field'],
        );
    }
}