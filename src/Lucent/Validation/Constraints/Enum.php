<?php

declare(strict_types=1);

namespace Lucent\Validation\Constraints;

use Closure;
use Lucent\Validation\Constraint;
use Lucent\Validation\FieldContext;
use Override;

/**
 * Validates a value against the cases of a PHP enum.
 *
 * Accepts a backed enum's backing value (string or int) or the enum instance
 * itself, and normalizes the value to the matching enum case on success. For
 * a pure (non-backed) enum, the enum instance or its case name is accepted.
 *
 * ```php
 * new Enum(ChallengeMethod::class);
 * ```
 */
final class Enum extends Constraint
{
    /**
     * Create an enum constraint.
     *
     * @param class-string<\UnitEnum> $enum The enum class whose cases are allowed.
     * @throws \InvalidArgumentException If the given class is not an enum.
     */
    public function __construct(private readonly string $enum)
    {
        if (!enum_exists($enum)) {
            throw new \InvalidArgumentException("Enum constraint requires a valid enum class, got '{$enum}'.");
        }
    }

    /**
     * @return string|Closure(FieldContext): string The default error message.
     */
    #[Override]
    protected function defaultMessage(): string|Closure|null
    {
        return fn(FieldContext $ctx) => "The {$ctx->field} must be one of: " . implode(', ', $this->allowedValues());
    }

    /**
     * Validate and normalize a value against the enum's cases.
     *
     * A value that is already an instance of the enum passes as-is. A backed
     * enum also accepts its backing value (string or int); a pure enum
     * accepts its case name. On success the value is normalized to the enum
     * case.
     *
     * @param FieldContext $ctx The context of the field being validated.
     * @return bool True if the value matches an enum case, false otherwise.
     */
    #[Override]
    public function validate(FieldContext $ctx): bool
    {
        $value = $ctx->value;

        if ($value instanceof $this->enum) {
            // Store the instance so it is retrievable from the result (raw
            // object values are not seeded by the validator).
            $ctx->normalize($value);
            return true;
        }

        $case = $this->resolveCase($value);

        if ($case === null) {
            return false;
        }

        $ctx->normalize($case);
        return true;
    }

    /**
     * Resolve a raw value to an enum case, or null if it matches none.
     *
     * @param mixed $value The raw value.
     * @return \UnitEnum|null The matching enum case, or null.
     */
    private function resolveCase(mixed $value): ?\UnitEnum
    {
        foreach ($this->enum::cases() as $case) {
            if ($case instanceof \BackedEnum) {
                if ($value === $case->value) {
                    return $case;
                }
                continue;
            }

            if ($value === $case->name) {
                return $case;
            }
        }

        return null;
    }

    /**
     * The human-readable list of allowed values for the error message.
     *
     * @return list<string> The backing values (or case names) of the enum.
     */
    private function allowedValues(): array
    {
        $values = [];

        foreach ($this->enum::cases() as $case) {
            $values[] = $case instanceof \BackedEnum
                ? (string) $case->value
                : $case->name;
        }

        return $values;
    }
}