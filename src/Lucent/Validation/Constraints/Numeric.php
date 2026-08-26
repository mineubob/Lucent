<?php

declare(strict_types=1);

namespace Lucent\Validation\Constraints;

use Closure;
use Lucent\Validation\Constraint;
use Lucent\Validation\FieldContext;
use Override;

/**
 * Validates that a value is numeric.
 *
 * Numeric strings are normalized to a number on success.
 */
final class Numeric extends Constraint
{
    /**
     * @return string|Closure(FieldContext): string The default error message.
     */
    #[Override]
    protected function defaultMessage(): string|Closure|null
    {
        return fn(FieldContext $ctx) => "The {$ctx->field} must be a number";
    }

    /**
     * Validate that the value is numeric.
     *
     * Integers and floats pass as-is. Numeric strings are normalized to a
     * number via {@see FieldContext::normalize()} before passing.
     *
     * @param FieldContext $ctx The context of the field being validated.
     * @return bool True if the value is numeric, false otherwise.
     */
    #[Override]
    public function validate(FieldContext $ctx): bool
    {
        if (is_int($ctx->value) || is_float($ctx->value)) {
            return true;
        }

        if (is_string($ctx->value) && is_numeric($ctx->value)) {
            $ctx->normalize(+$ctx->value);
            return true;
        }

        return false;
    }
}
