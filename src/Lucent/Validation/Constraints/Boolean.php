<?php

declare(strict_types=1);

namespace Lucent\Validation\Constraints;

use Closure;
use Lucent\Validation\Constraint;
use Lucent\Validation\FieldContext;
use Override;

/**
 * Validates and normalizes a boolean value.
 *
 * Accepts real booleans as well as the common string/numeric representations
 * `'true'`, `'false'`, `'1'`, `'0'`, `1`, and `0`. The string forms are
 * case-insensitive (`'TRUE'`, `'True'`). On success the value is normalized
 * to a real `bool` via {@see FieldContext::normalize()}, so downstream code
 * can rely on a typed boolean.
 */
final class Boolean extends Constraint
{
    /**
     * @return string|Closure(FieldContext): string The default error message.
     */
    #[Override]
    protected function defaultMessage(): string|Closure|null
    {
        return fn(FieldContext $ctx) => "The {$ctx->field} must be a boolean.";
    }

    /**
     * Validate and normalize a boolean value.
     *
     * Real booleans pass as-is. The strings `'true'`/`'1'` and
     * `'false'`/`'0'` (case-insensitive for the word forms) and the integers
     * `1`/`0` are normalized to a real boolean. Any other value fails.
     *
     * @param FieldContext $ctx The context of the field being validated.
     * @return bool True if the value is a valid boolean representation, false otherwise.
     */
    #[Override]
    public function validate(FieldContext $ctx): bool
    {
        $value = $ctx->value;

        if (is_bool($value)) {
            return true;
        }

        if ($value === 1 || $value === '1') {
            $ctx->normalize(true);
            return true;
        }

        if ($value === 0 || $value === '0') {
            $ctx->normalize(false);
            return true;
        }

        if (is_string($value)) {
            $normalized = strtolower($value);

            if ($normalized === 'true') {
                $ctx->normalize(true);
                return true;
            }

            if ($normalized === 'false') {
                $ctx->normalize(false);
                return true;
            }
        }

        return false;
    }
}