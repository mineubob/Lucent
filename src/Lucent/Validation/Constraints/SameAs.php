<?php

declare(strict_types=1);

namespace Lucent\Validation\Constraints;

use Closure;
use Lucent\Validation\Constraint;
use Lucent\Validation\FieldContext;
use Override;

/**
 * Validates that a field's value strictly equals another field's value.
 *
 * The comparison uses strict `===` on the **raw** values, so a numeric string
 * (`"5"`) does not equal an integer (`5`). If the other field is normalized by
 * an earlier constraint (e.g. {@see \Lucent\Validation\Constraints\Numeric}
 * casting `"5"` to `5`), the comparison uses the normalized value stored in
 * the result. The strict comparison intentionally prevents type-juggling
 * bypasses.
 */
final class SameAs extends Constraint
{
    /**
     * Create a constraint requiring a field to match another field.
     *
     * @param string $otherField The name of the field to compare against.
     */
    public function __construct(private readonly string $otherField) {}

    /**
     * @return string|Closure(FieldContext): string The default error message.
     */
    #[Override]
    protected function defaultMessage(): string|Closure|null
    {
        return fn(FieldContext $ctx) => "{$ctx->field} must match the value of {$this->otherField}";
    }

    /**
     * Validate that the value strictly equals the other field's value.
     *
     * @param FieldContext $ctx The context of the field being validated.
     * @return bool True if the values match, false otherwise.
     */
    #[Override]
    public function validate(FieldContext $ctx): bool
    {
        return $ctx->value === $ctx->valueOf($this->otherField);
    }
}
