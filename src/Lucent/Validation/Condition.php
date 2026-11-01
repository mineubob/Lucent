<?php

declare(strict_types=1);

namespace Lucent\Validation;

/**
 * Matches a field's value against a declarative condition.
 *
 * The shared condition engine behind the conditional constraints
 * ({@see \Lucent\Validation\Combinators\Cases},
 * {@see \Lucent\Validation\Constraints\RequiredIf},
 * {@see \Lucent\Validation\Constraints\ProhibitedIf},
 * {@see \Lucent\Validation\Constraints\Equals}, and
 * {@see \Lucent\Validation\Constraints\In}) so their matching semantics stay
 * identical.
 *
 * Accepted condition forms:
 *
 * 1. **Scalar** — strict `===` comparison against the field's value.
 * 2. **List of scalars** — membership: the value matches when it strictly
 *    equals any element.
 * 3. **Enum case** — matches the case instance or its backing value.
 * 4. **Constraint** — the constraint is validated against the field in
 *    isolation (a throwaway result, the same mechanism
 *    {@see FieldContext::branch()} uses) and only its boolean outcome is
 *    used. Errors and normalizations the condition produced are discarded:
 *    a failing condition means "take the other branch", never an error.
 *    This is also the normalize-and-check form: compose a normalizer with
 *    the check, e.g. `All::of(new Trim(), new Email())`.
 *
 * Conditions are evaluated against the field's **normalized** value: the
 * scheduler ({@see \Lucent\Validation\Combinators\Shape}) guarantees a
 * referenced field's constraints — including its normalizers — run before a
 * dependent condition reads it, so plain strict comparison is sufficient and
 * no pre-comparison transform is needed.
 */
final class Condition
{
    /**
     * @param mixed $condition The condition to match against.
     */
    private function __construct(
        private readonly mixed $condition,
    ) {}

    /**
     * Create a condition from any supported form.
     *
     * @param mixed $condition Scalar, list of scalars, enum case, Constraint, or closure.
     * @return self The condition.
     * @throws \InvalidArgumentException When the condition form is not supported.
     */
    public static function of(mixed $condition): self
    {
        if ($condition instanceof Constraint) {
            return new self($condition);
        }

        if (is_array($condition)) {
            if ($condition === []) {
                throw new \InvalidArgumentException('An array condition must not be empty.');
            }

            foreach ($condition as $element) {
                if (!is_scalar($element) && !$element instanceof \UnitEnum) {
                    throw new \InvalidArgumentException(
                        'Array conditions accept only scalars and enum cases, got ' . get_debug_type($element) . '.',
                    );
                }
            }

            return new self($condition);
        }

        if (is_scalar($condition) || $condition instanceof \UnitEnum) {
            return new self($condition);
        }

        throw new \InvalidArgumentException(
            'Condition must be a scalar, a list of scalars, an enum case, or a Constraint, got ' . get_debug_type($condition) . '.',
        );
    }

    /**
     * Evaluate the condition against a field's context.
     *
     * @param FieldContext $ctx The context of the field the condition reads.
     * @return bool True when the condition matches.
     */
    public function matches(FieldContext $ctx): bool
    {
        return $this->evaluate($ctx->value, $ctx);
    }

    /**
     * Dispatch the evaluation by condition form.
     *
     * @param mixed $value The field value (typically normalized).
     * @param FieldContext $ctx The field's context, for the constraint form.
     * @return bool Whether the condition matched.
     */
    private function evaluate(mixed $value, FieldContext $ctx): bool
    {
        if ($this->condition instanceof Constraint) {
            // Validate in isolation: the condition's errors and normalizations
            // must never leak into the shared result — a failing condition
            // simply means "take the other branch". This mirrors the
            // branch() mechanism used by the One and Any combinators.
            $branch = $ctx->withResult(new Result());

            return $this->condition->validate($branch);
        }

        if (is_array($this->condition)) {
            foreach ($this->condition as $element) {
                if ($this->matchesValue($value, $element)) {
                    return true;
                }
            }

            return false;
        }

        return $this->matchesValue($value, $this->condition);
    }

    /**
     * Compare a value against a scalar or enum-case condition element.
     *
     * Conditions run against normalized values (the scheduler guarantees a
     * referenced field's constraints — including normalizers — run first), so
     * the primary comparison is strict `===`. One asymmetry remains: a value
     * that is an enum instance also matches a scalar equal to its backing
     * value or name, so a condition written against the raw form
     * (`is: 'card'`) still matches when an `Enum` constraint normalized the
     * field to `PaymentType::Card`.
     *
     * @param mixed $value The field value (typically normalized).
     * @param mixed $expected The scalar or enum case to match.
     * @return bool Whether the value matches.
     */
    private function matchesValue(mixed $value, mixed $expected): bool
    {
        if ($expected instanceof \UnitEnum) {
            return $value === $expected
                || ($expected instanceof \BackedEnum && $value === $expected->value);
        }

        if ($value instanceof \BackedEnum) {
            return $value === $expected || $value->value === $expected;
        }

        if ($value instanceof \UnitEnum) {
            return $value === $expected || $value->name === $expected;
        }

        return $value === $expected;
    }
}
