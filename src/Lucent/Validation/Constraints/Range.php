<?php

declare(strict_types=1);

namespace Lucent\Validation\Constraints;

use Closure;
use Lucent\Validation\Constraint;
use Lucent\Validation\FieldContext;
use Override;

/**
 * Validates that a numeric value falls within a given range.
 *
 * Numeric strings are cast to a number and normalized on success.
 */
final class Range extends Constraint
{
    /**
     * Create a numeric range constraint.
     *
     * @param int|float|null $min The minimum value, or null for no minimum.
     * @param int|float|null $max The maximum value, or null for no maximum.
     * @throws \InvalidArgumentException If both $min and $max are null.
     */
    public function __construct(private readonly int|float|null $min = null, private readonly int|float|null $max = null)
    {
        if ($min === null && $max === null) {
            throw new \InvalidArgumentException('Range constraint requires at least one of $min or $max.');
        }
    }

    /**
     * @return string|Closure(FieldContext): string The default error message.
     */
    #[Override]
    protected function defaultMessage(): string|Closure|null
    {
        return fn(FieldContext $ctx) => match (true) {
            $this->min !== null && $this->max === null => "The {$ctx->field} field must be at least {$this->min}.",
            $this->min === null && $this->max !== null => "The {$ctx->field} field must be at most {$this->max}.",
            default => "The {$ctx->field} field must be between {$this->min} and {$this->max}."
        };
    }

    /**
     * Validate that the value falls within the configured range.
     *
     * Numeric strings are cast to a number for the bounds check. The value is
     * only normalized (stored in the result) once it passes the bounds check,
     * so an out-of-range value is never stored as if it were valid.
     * Non-numeric values always fail.
     *
     * @param FieldContext $ctx The context of the field being validated.
     * @return bool True if the value is within range, false otherwise.
     */
    #[Override]
    public function validate(FieldContext $ctx): bool
    {
        $value = $ctx->value;

        if (is_string($value) && is_numeric($value)) {
            $value = +$value;
        }

        if (!is_int($value) && !is_float($value)) {
            return false;
        }

        $inRange = match (true) {
            $this->min !== null && $value < $this->min => false,
            $this->max !== null && $value > $this->max => false,
            default => true
        };

        if ($inRange) {
            $ctx->normalize($value);
        }

        return $inRange;
    }
}
