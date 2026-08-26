<?php

declare(strict_types=1);

namespace Lucent\Validation\Constraints;

use Closure;
use Lucent\Validation\Constraint;
use Lucent\Validation\FieldContext;
use Override;

/**
 * Validates that every element of an array value is unique.
 *
 * Useful for lists of tags, ids, or other values that must not repeat.
 * Comparison is strict (`===`), so `1` and `'1'` are treated as distinct.
 */
final class Distinct extends Constraint
{
    /**
     * @return string|Closure(FieldContext): string The default error message.
     */
    #[Override]
    protected function defaultMessage(): string|Closure|null
    {
        return fn(FieldContext $ctx) => "The {$ctx->field} must not contain duplicate values.";
    }

    /**
     * Validate that all array elements are unique.
     *
     * Non-array values always fail. Elements are compared strictly, so
     * `1` and `'1'` are considered distinct.
     *
     * @param FieldContext $ctx The context of the field being validated.
     * @return bool True if the value is an array with unique elements, false otherwise.
     */
    #[Override]
    public function validate(FieldContext $ctx): bool
    {
        if (!is_array($ctx->value)) {
            return false;
        }

        $seen = [];

        foreach ($ctx->value as $element) {
            // Serialize each element so strict identity is preserved: `1` and
            // `'1'` serialize differently and are treated as distinct, while
            // two identical values collide on the same key.
            $key = serialize($element);

            if (isset($seen[$key])) {
                return false;
            }

            $seen[$key] = true;
        }

        return true;
    }
}