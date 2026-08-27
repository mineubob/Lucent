<?php

namespace Tests\Support\Stubs;

use Closure;
use Lucent\Validation\Constraint;
use Lucent\Validation\FieldContext;

/**
 * A constraint that always passes.
 *
 * defaultMessage() returns null so that calling message() on a passing
 * constraint is harmless (no error is recorded). This mirrors the framework's
 * own NormalizeConstraint and TracksFailure, which return null when their
 * message is requested in an invalid state.
 */
class PassingConstraint extends Constraint
{
    protected function defaultMessage(): string|Closure|null
    {
        return null;
    }

    public function validate(FieldContext $ctx): bool
    {
        return true;
    }
}