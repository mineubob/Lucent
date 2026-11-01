<?php

declare(strict_types=1);

namespace Lucent\Validation\Constraints;

use Closure;
use Lucent\Validation\Condition;
use Lucent\Validation\Constraint;
use Lucent\Validation\FieldContext;
use Override;

/**
 * Validates that a field's value equals an expected value.
 *
 * The single-field counterpart to {@see SameAs}: instead of comparing two
 * fields, it compares the field's value against a declared expectation.
 * Matching semantics are shared with {@see \Lucent\Validation\Combinators\Cases}
 * via {@see \Lucent\Validation\Condition} — strict scalar equality and
 * enum-case triple-match (instance, backing value, or name) — so an enum
 * case matches whether the field holds the raw backing value or the
 * normalized instance.
 *
 * Composes with the combinator constraints, e.g.
 * `All::of(new Enum(PaymentType::class), new Equals(PaymentType::Paypal))`
 * validates membership and then exact-case equality.
 *
 * ```php
 * 'status' => new Equals('active'),
 * 'type'   => new Equals(PaymentType::Card),
 * ```
 */
final class Equals extends Constraint
{
    /**
     * The compiled condition.
     */
    private readonly Condition $condition;

    /**
     * The expected value, kept for the error message.
     */
    private readonly mixed $expected;

    /**
     * Create an equality constraint.
     *
     * @param mixed $expected The expected value: a scalar or enum case. Use
     *        {@see \Lucent\Validation\Constraints\In} for multiple candidates.
     */
    public function __construct(mixed $expected)
    {
        if (is_array($expected)) {
            throw new \InvalidArgumentException(
                'Equals accepts a single scalar or enum case; use In for multiple candidates.',
            );
        }

        $this->expected = $expected;
        $this->condition = Condition::of($expected);
    }

    /**
     * @return string|Closure(FieldContext): string The default error message.
     */
    #[Override]
    protected function defaultMessage(): string|Closure|null
    {
        return fn(FieldContext $ctx) => "The {$ctx->field} must equal {$this->describe()}.";
    }

    /**
     * Validate that the value equals the expected value.
     *
     * @param FieldContext $ctx The context of the field being validated.
     * @return bool True if the value matches, false otherwise.
     */
    #[Override]
    public function validate(FieldContext $ctx): bool
    {
        return $this->condition->matches($ctx);
    }

    /**
     * Describe the expected value for the error message.
     *
     * @return string A human-readable description of the expectation.
     */
    private function describe(): string
    {
        $expected = $this->expected;

        if ($expected instanceof \BackedEnum) {
            return (string) $expected->value;
        }

        if ($expected instanceof \UnitEnum) {
            return $expected->name;
        }

        return var_export($expected, true);
    }
}
