<?php

declare(strict_types=1);

namespace Lucent\Validation\Constraints;

use Closure;
use Lucent\Validation\Constraint;
use Lucent\Validation\FieldContext;
use Override;

/**
 * Validates that a value is a number (integer or float).
 *
 * An explicit type check that complements {@see Numeric}, which coerces
 * numeric strings to a number. This constraint rejects numeric strings,
 * requiring a genuine `int` or `float`.
 */
final class Number extends Constraint
{
    /**
     * @return string|Closure(FieldContext): string The default error message.
     */
    #[Override]
    protected function defaultMessage(): string|Closure|null
    {
        return fn(FieldContext $ctx) => "The {$ctx->field} must be a number.";
    }

    /**
     * Validate that the value is an integer or float.
     *
     * @param FieldContext $ctx The context of the field being validated.
     * @return bool True if the value is an integer or float, false otherwise.
     */
    #[Override]
    public function validate(FieldContext $ctx): bool
    {
        return is_int($ctx->value) || is_float($ctx->value);
    }
}