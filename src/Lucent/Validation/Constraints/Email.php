<?php

declare(strict_types=1);

namespace Lucent\Validation\Constraints;

use Closure;
use Lucent\Validation\Constraint;
use Lucent\Validation\FieldContext;
use Override;

/**
 * Validates that a value is a well-formed email address.
 */
final class Email extends Constraint
{
    /**
     * @return string|Closure(FieldContext): string The default error message.
     */
    #[Override]
    protected function defaultMessage(): string|Closure|null
    {
        return fn(FieldContext $ctx) => "The {$ctx->field} must be a valid email address.";
    }

    /**
     * Validate that the value is a well-formed email address.
     *
     * @param FieldContext $ctx The context of the field being validated.
     * @return bool True if the value is a valid email, false otherwise.
     */
    #[Override]
    public function validate(FieldContext $ctx): bool
    {
        return is_string($ctx->value)
            && filter_var($ctx->value, FILTER_VALIDATE_EMAIL) !== false;
    }
}