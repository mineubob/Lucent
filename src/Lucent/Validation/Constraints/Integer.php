<?php

declare(strict_types=1);

namespace Lucent\Validation\Constraints;

use Closure;
use Lucent\Validation\Constraint;
use Lucent\Validation\FieldContext;
use Override;

/**
 * Validates that a value is an integer.
 *
 * An explicit type check that complements {@see Numeric}, which coerces
 * numeric strings to a number. This constraint rejects floats and numeric
 * strings, requiring a genuine `int`.
 */
final class Integer extends Constraint
{
    /**
     * @return string|Closure(FieldContext): string The default error message.
     */
    #[Override]
    protected function defaultMessage(): string|Closure|null
    {
        return fn(FieldContext $ctx) => "The {$ctx->field} must be an integer.";
    }

    /**
     * Validate that the value is an integer.
     *
     * @param FieldContext $ctx The context of the field being validated.
     * @return bool True if the value is an integer, false otherwise.
     */
    #[Override]
    public function validate(FieldContext $ctx): bool
    {
        return is_int($ctx->value);
    }
}