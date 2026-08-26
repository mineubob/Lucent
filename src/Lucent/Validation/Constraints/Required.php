<?php

declare(strict_types=1);

namespace Lucent\Validation\Constraints;

use Closure;
use Lucent\Validation\Constraint;
use Lucent\Validation\FieldContext;
use Override;

/**
 * Validates that a field has a non-empty value.
 *
 * Null, empty strings, and empty arrays are all considered missing.
 */
final class Required extends Constraint
{
    /**
     * @return string|Closure(FieldContext): string The default error message.
     */
    #[Override]
    protected function defaultMessage(): string|Closure|null
    {
        return fn(FieldContext $ctx) => "The {$ctx->field} is required.";
    }

    /**
     * Validate that the field has a non-empty value.
     *
     * Null, empty strings, and empty arrays are all considered missing.
     *
     * @param FieldContext $ctx The context of the field being validated.
     * @return bool True if the value is present, false otherwise.
     */
    #[Override]
    public function validate(FieldContext $ctx): bool
    {
        $value = $ctx->value;

        // null and empty string are missing.
        if ($value === null || $value === '') {
            return false;
        }

        // An empty array is missing.
        if (is_array($value) && $value === []) {
            return false;
        }

        return true;
    }
}
