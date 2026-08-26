<?php

namespace Tests\Unit\Validation\Constraints;

use Lucent\Validation\Constraints\Unique;
use PHPUnit\Framework\TestCase;
use Tests\Support\Concerns\BuildsValidationRequests;

class UniqueTest extends TestCase
{
    use BuildsValidationRequests;

    private function validate(mixed $value, Unique $constraint): \Lucent\Validation\Result
    {
        return $this->validateField('field', ['field' => $value], $constraint);
    }

    // ─── no conflicting row passes ─────────────────────────────────────────

    public function test_passes_when_no_conflicting_row_exists(): void
    {
        $constraint = new Unique(fn () => false);

        $this->assertFalse($this->validate('foo@example.com', $constraint)->hasErrors());
    }

    // ─── conflicting row fails ─────────────────────────────────────────────

    public function test_fails_when_conflicting_row_exists(): void
    {
        $constraint = new Unique(fn () => true);

        $this->assertTrue($this->validate('foo@example.com', $constraint)->hasErrors());
    }

    public function test_receives_the_field_value(): void
    {
        $received = null;

        $constraint = new Unique(function (mixed $value) use (&$received): bool {
            $received = $value;
            return false;
        });

        $this->validate('bar@example.com', $constraint);

        $this->assertSame('bar@example.com', $received);
    }

    // ─── empty values pass (presence is Required's job) ───────────────────

    public function test_null_passes(): void
    {
        $constraint = new Unique(fn () => true);

        $this->assertFalse($this->validate(null, $constraint)->hasErrors());
    }

    public function test_empty_string_passes(): void
    {
        $constraint = new Unique(fn () => true);

        $this->assertFalse($this->validate('', $constraint)->hasErrors());
    }

    public function test_empty_array_passes(): void
    {
        $constraint = new Unique(fn () => true);

        $this->assertFalse($this->validate([], $constraint)->hasErrors());
    }

    // ─── message ───────────────────────────────────────────────────────────

    public function test_error_message(): void
    {
        $constraint = new Unique(fn () => true);

        $result = $this->validate('foo@example.com', $constraint);

        $this->assertSame(
            ['The field has already been taken.'],
            $result->errors()['field'],
        );
    }

    public function test_custom_message_via_with_message(): void
    {
        $constraint = (new Unique(fn () => true))
            ->withMessage('That email is already registered.');

        $result = $this->validate('foo@example.com', $constraint);

        $this->assertSame(
            ['That email is already registered.'],
            $result->errors()['field'],
        );
    }
}