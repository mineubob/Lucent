<?php

declare(strict_types=1);

namespace Lucent\Validation\Constraints;

use Closure;
use Lucent\Validation\Constraint;
use Lucent\Validation\FieldContext;
use Override;

/**
 * Validates that a value is a string.
 *
 * An explicit type check that composes with {@see \Lucent\Validation\Combinators\Optional}
 * and {@see \Lucent\Validation\Combinators\All}. Unlike {@see Length}, which
 * implicitly requires a string, this constraint gives a clear, dedicated error
 * message when the value is not a string.
 */
final class Str extends Constraint
{
    /**
     * @return string|Closure(FieldContext): string The default error message.
     */
    #[Override]
    protected function defaultMessage(): string|Closure|null
    {
        return fn(FieldContext $ctx) => "The {$ctx->field} must be a string.";
    }

    /**
     * Validate that the value is a string.
     *
     * @param FieldContext $ctx The context of the field being validated.
     * @return bool True if the value is a string, false otherwise.
     */
    #[Override]
    public function validate(FieldContext $ctx): bool
    {
        return is_string($ctx->value);
    }
}