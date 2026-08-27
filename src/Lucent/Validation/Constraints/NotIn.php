<?php

declare(strict_types=1);

namespace Lucent\Validation\Constraints;

use Closure;
use Lucent\Validation\Constraint;
use Lucent\Validation\FieldContext;
use Override;

/**
 * Validates that a value is not one of a set of forbidden values.
 *
 * The complement of a hypothetical `In` constraint: rejects a configured set
 * of values and passes everything else. Comparison is strict (`===`).
 *
 * ```php
 * new NotIn(['admin', 'root']);
 * ```
 */
final class NotIn extends Constraint
{
    /**
     * Create a "not in" constraint.
     *
     * @param array<int, mixed> $values The forbidden values.
     */
    public function __construct(private readonly array $values) {}

    /**
     * @return string|Closure(FieldContext): string The default error message.
     */
    #[Override]
    protected function defaultMessage(): string|Closure|null
    {
        return fn(FieldContext $ctx) => "The {$ctx->field} must not be one of: " . implode(', ', array_map($this->stringify(...), $this->values)) . '.';
    }

    /**
     * Safely render a forbidden value for the error message.
     *
     * Handles any value type without relying on `strval`, which throws on
     * objects without `__toString` and warns on arrays.
     *
     * @param mixed $value The value to render.
     * @return string A human-readable representation.
     */
    private function stringify(mixed $value): string
    {
        return match (true) {
            is_string($value)  => $value,
            is_int($value), is_float($value) => (string) $value,
            is_bool($value)    => $value ? 'true' : 'false',
            $value === null    => 'null',
            is_array($value)   => 'array',
            is_object($value)  => method_exists($value, '__toString') ? (string) $value : $value::class,
            default            => 'unknown',
        };
    }

    /**
     * Validate that the value is not in the forbidden set.
     *
     * Comparison is strict (`===`), so `1` and `'1'` are treated as distinct.
     *
     * @param FieldContext $ctx The context of the field being validated.
     * @return bool True if the value is not forbidden, false otherwise.
     */
    #[Override]
    public function validate(FieldContext $ctx): bool
    {
        return !in_array($ctx->value, $this->values, true);
    }
}