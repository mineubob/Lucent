<?php

declare(strict_types=1);

namespace Lucent\Validation\Constraints;

use Closure;
use Lucent\Validation\Constraint;
use Lucent\Validation\FieldContext;
use Override;

/**
 * Validates the length of a string or array value.
 *
 * Strings are measured in characters, arrays in item count.
 */
final class Length extends Constraint
{
    /**
     * Create a length constraint.
     *
     * @param int|null $min The minimum length, or null for no minimum.
     * @param int|null $max The maximum length, or null for no maximum.
     * @throws \InvalidArgumentException If both $min and $max are null.
     */
    public function __construct(private readonly int|null $min = null, private readonly int|null $max = null)
    {
        if ($min === null && $max === null) {
            throw new \InvalidArgumentException('Length constraint requires at least one of $min or $max.');
        }
    }

    /**
     * @return string|Closure(FieldContext): string The default error message.
     */
    #[Override]
    protected function defaultMessage(): string|Closure|null
    {
        return fn(FieldContext $ctx) => match (true) {
            $this->min !== null && $this->max === null => "The {$ctx->field} field must be at least {$this->min} {$this->unit($ctx)} long.",
            $this->min === null && $this->max !== null => "The {$ctx->field} field must not exceed {$this->max} {$this->unit($ctx)} long.",
            default => "The {$ctx->field} field must be between {$this->min} and {$this->max} {$this->unit($ctx)} long."
        };
    }

    /**
     * Validate the length of a string or array value.
     *
     * Strings are measured in characters (via `mb_strlen`), arrays in item
     * count. Non-string, non-array values always fail.
     *
     * @param FieldContext $ctx The context of the field being validated.
     * @return bool True if the value is within the allowed length, false otherwise.
     */
    #[Override]
    public function validate(FieldContext $ctx): bool
    {
        $length = match (true) {
            is_string($ctx->value) => mb_strlen($ctx->value),
            is_array($ctx->value)  => count($ctx->value),
            default                => null,
        };

        if ($length === null) {
            return false;
        }

        return match (true) {
            $this->min !== null && $length < $this->min => false,
            $this->max !== null && $length > $this->max => false,
            default => true
        };
    }

    /**
     * Describe the unit of measurement for the current value.
     *
     * @param FieldContext $ctx The context of the field being validated.
     * @return string Either `items` for arrays or `characters` for strings.
     */
    private function unit(FieldContext $ctx): string
    {
        return is_array($ctx->value) ? 'items' : 'characters';
    }
}
