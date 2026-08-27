<?php

namespace Tests\Support\Stubs;

use Closure;
use Lucent\Validation\Constraint;
use Lucent\Validation\FieldContext;

/**
 * A minimal constraint that always fails with a configurable default message.
 *
 * The message defaults to 'constraint failed' but may be overridden with a
 * literal string, a closure, or null (to exercise the null-message skip path).
 */
class FailingConstraint extends Constraint
{
    public function __construct(private readonly string|Closure|null $message = 'constraint failed') {}

    protected function defaultMessage(): string|Closure|null
    {
        return $this->message;
    }

    public function validate(FieldContext $ctx): bool
    {
        return false;
    }
}