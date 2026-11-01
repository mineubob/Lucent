<?php

declare(strict_types=1);

namespace Lucent\Validation\Constraints;

use Closure;
use Lucent\Validation\Condition;
use Lucent\Validation\Constraint;
use Lucent\Validation\FieldContext;
use Override;

/**
 * Validates that a field's value is one of the allowed candidates.
 *
 * The list-membership counterpart to {@see Equals}: the field matches when
 * its value strictly equals any candidate. Matching semantics are shared with
 * {@see \Lucent\Validation\Combinators\Cases} via
 * {@see \Lucent\Validation\Condition} — scalars compare with strict `===`,
 * and enum-case candidates match the case instance, its backing value, or its
 * name, so they work against both raw and normalized values.
 *
 * ```php
 * 'scheme' => new In(['visa', 'mc']),
 * 'type'   => new In([PaymentType::Card, PaymentType::Paypal]),
 * ```
 */
final class In extends Constraint
{
    /**
     * The compiled condition.
     */
    private readonly Condition $condition;

    /**
     * @param list<mixed> $candidates The allowed values.
     */
    private readonly array $candidates;

    /**
     * Create a membership constraint.
     *
     * @param list<mixed> $candidates The allowed values: scalars and/or enum cases.
     */
    public function __construct(array $candidates)
    {
        if ($candidates === []) {
            throw new \InvalidArgumentException('In requires at least one candidate value.');
        }

        $this->candidates = $candidates;
        $this->condition = Condition::of($candidates);
    }

    /**
     * @return string|Closure(FieldContext): string The default error message.
     */
    #[Override]
    protected function defaultMessage(): string|Closure|null
    {
        return fn(FieldContext $ctx) => "The {$ctx->field} must be one of: " . implode(', ', $this->describeAll()) . '.';
    }

    /**
     * Validate that the value matches any candidate.
     *
     * @param FieldContext $ctx The context of the field being validated.
     * @return bool True if the value matches a candidate, false otherwise.
     */
    #[Override]
    public function validate(FieldContext $ctx): bool
    {
        return $this->condition->matches($ctx);
    }

    /**
     * Describe all candidates for the error message.
     *
     * @return list<string> Human-readable candidate descriptions.
     */
    private function describeAll(): array
    {
        return array_map(
            static function (mixed $candidate): string {
                if ($candidate instanceof \BackedEnum) {
                    return (string) $candidate->value;
                }

                if ($candidate instanceof \UnitEnum) {
                    return $candidate->name;
                }

                return var_export($candidate, true);
            },
            $this->candidates,
        );
    }
}
