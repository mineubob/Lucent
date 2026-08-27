<?php

declare(strict_types=1);

namespace Lucent\Validation\Constraints;

use Carbon\Carbon;
use Closure;
use Lucent\Validation\Constraint;
use Lucent\Validation\FieldContext;
use Override;

/**
 * Validates that a value is a date in a given format.
 *
 * On success the value is normalized to a {@see Carbon} instance.
 */
final class Date extends Constraint
{
    /**
     * Create a date constraint.
     *
     * @param string $format The expected date format (defaults to `Y-m-d`).
     */
    public function __construct(private readonly string $format = 'Y-m-d') {}

    /**
     * @return string|Closure(FieldContext): string The default error message.
     */
    #[Override]
    protected function defaultMessage(): string|Closure|null
    {
        return fn(FieldContext $ctx) => "The {$ctx->field} must be a valid date in {$this->format} format.";
    }

    /**
     * Validate that the value is a date in the expected format.
     *
     * A round-trip check rejects rollovers (e.g. `2023-13-31` becoming
     * `2024-01-31`) and non-strict input (e.g. `2023-1-1`). On success the
     * value is normalized to a {@see Carbon} instance.
     *
     * @param FieldContext $ctx The context of the field being validated.
     * @return bool True if the value is a valid date, false otherwise.
     */
    #[Override]
    public function validate(FieldContext $ctx): bool
    {
        if (!is_string($ctx->value)) {
            return false;
        }

        try {
            $date = Carbon::createFromFormat($this->format, $ctx->value);
        } catch (\Throwable) {
            // Carbon throws instead of returning false for some malformed
            // input (e.g. "not-a-date" or an empty string), and may throw
            // other exception types for edge cases. Treat any failure as an
            // invalid date rather than letting the exception propagate.
            return false;
        }

        if ($date === false) {
            return false;
        }

        // Round-trip check: reject rollovers (e.g. 2023-13-31 -> 2024-01-31)
        // and non-strict input (e.g. 2023-1-1).
        if ($date->format($this->format) !== $ctx->value) {
            return false;
        }

        $ctx->normalize($date);
        return true;
    }
}