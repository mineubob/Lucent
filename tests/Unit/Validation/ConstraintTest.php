<?php

namespace Tests\Unit\Validation;

use Lucent\Validation\FieldContext;
use Lucent\Validation\Result;
use PHPUnit\Framework\TestCase;
use Tests\Support\Stubs\FailingConstraint;
use Tests\Support\Stubs\PassingConstraint;

class ConstraintTest extends TestCase
{
    private function context(string $field = 'name', mixed $value = 'Ada'): FieldContext
    {
        return new FieldContext($field, $value, true, new Result(), null, null);
    }

    // ─── withMessage(string) ───────────────────────────────────────────────

    public function test_with_message_string_overrides_default(): void
    {
        $constraint = (new FailingConstraint())->withMessage('custom message');

        $this->assertSame('custom message', $constraint->message($this->context()));
    }

    public function test_with_message_returns_static_for_chaining(): void
    {
        $constraint = new FailingConstraint();

        $this->assertInstanceOf(FailingConstraint::class, $constraint->withMessage('custom'));
    }

    // ─── withMessage(closure) ──────────────────────────────────────────────

    public function test_with_message_closure_receives_context_and_builds_dynamic_message(): void
    {
        $constraint = (new FailingConstraint())
            ->withMessage(fn (FieldContext $ctx) => "Error for {$ctx->field}");

        $this->assertSame('Error for name', $constraint->message($this->context('name')));
    }

    public function test_with_message_closure_can_use_value(): void
    {
        $constraint = (new FailingConstraint())
            ->withMessage(fn (FieldContext $ctx) => "Bad value: " . var_export($ctx->value, true));

        $this->assertSame("Bad value: 'Ada'", $constraint->message($this->context('name', 'Ada')));
    }

    // ─── message() fallback ────────────────────────────────────────────────

    public function test_message_falls_back_to_default_when_no_custom_set(): void
    {
        $constraint = new FailingConstraint();

        $this->assertSame('constraint failed', $constraint->message($this->context()));
    }

    public function test_message_returns_null_when_default_is_null(): void
    {
        $constraint = new FailingConstraint(null);

        $this->assertNull($constraint->message($this->context()));
    }

    // ─── recordConstraintFailure null-message skip ─────────────────────────

    public function test_record_constraint_failure_skips_error_when_message_null(): void
    {
        // A failing constraint with a null message must not record an error.
        $constraint = new FailingConstraint(null);
        $ctx = $this->context();

        $this->assertFalse($constraint->validate($ctx));
        $this->assertNull($constraint->message($ctx));
        $this->assertFalse($ctx->result->hasErrors());
    }

    public function test_passing_constraint_records_no_error(): void
    {
        $constraint = new PassingConstraint();
        $ctx = $this->context();

        $this->assertTrue($constraint->validate($ctx));
        $this->assertFalse($ctx->result->hasErrors());
    }

    public function test_passing_constraint_default_message_returns_null(): void
    {
        $constraint = new PassingConstraint();

        // A passing constraint reports no error message.
        $this->assertNull($constraint->message($this->context()));
    }
}