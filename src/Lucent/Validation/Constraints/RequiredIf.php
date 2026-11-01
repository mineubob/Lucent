<?php

declare(strict_types=1);

namespace Lucent\Validation\Constraints;

use Closure;
use Lucent\Validation\Condition;
use Lucent\Validation\Constraint;
use Lucent\Validation\FieldContext;
use Override;

/**
 * Requires a field when another field's value matches a condition.
 *
 * The cross-field counterpart to {@see \Lucent\Validation\Constraints\Required}:
 * the constraint passes silently when the referenced field does not match,
 * and enforces presence when it does. Typical use:
 *
 * ```php
 * 'card_number' => new RequiredIf('type', is: PaymentType::Card),
 * ```
 *
 * Matching semantics are shared with {@see \Lucent\Validation\Combinators\Cases}
 * via {@see \Lucent\Validation\Condition}: scalar strict equality, list
 * membership, enum-case matching, or constraint-as-condition. The scheduler
 * guarantees the referenced field's constraints (including normalizers) run
 * first, so conditions compare normalized values; for normalize-and-check
 * semantics compose a normalizer into a constraint condition, e.g.
 * `new All([new Trim(), new Equals('card')])`.
 *
 * When the referenced field already failed validation (e.g. its `Enum`
 * membership check), the constraint passes silently — a broken discriminator
 * should not produce a second, confusing error.
 */
final class RequiredIf extends Constraint
{
    /**
     * The compiled condition.
     */
    private readonly Condition $condition;

    /**
     * Create a conditional requirement.
     *
     * @param string $field The referenced field whose value drives the requirement.
     * @param mixed $is The condition the referenced field's value must match:
     *        a scalar, list of scalars, enum case, or Constraint.
     */
    public function __construct(
        private readonly string $field,
        mixed $is,
    ) {
        if ($field === '') {
            throw new \InvalidArgumentException('RequiredIf requires a non-empty field name.');
        }

        $this->condition = Condition::of($is);
    }

    /**
     * Declare the referenced field, for scheduler ordering.
     *
     * @return list<string> The referenced sibling field names.
     */
    #[Override]
    public function referencedFields(): array
    {
        return [$this->field];
    }

    /**
     * @return string|Closure(FieldContext): string The default error message.
     */
    #[Override]
    protected function defaultMessage(): string|Closure|null
    {
        return fn(FieldContext $ctx) => "The {$ctx->field} is required when {$this->field} matches.";
    }

    /**
     * Require presence when the referenced field matches.
     *
     * @param FieldContext $ctx The context of the field being validated.
     * @return bool True when the referenced field does not match, the field is
     *         present with a non-empty value, or the referenced field already
     *         failed; false when the field is required but missing/empty.
     */
    #[Override]
    public function validate(FieldContext $ctx): bool
    {
        $referenced = $ctx->child($this->field, null, false);

        // Skip guard: a failed referenced field means the discriminator is
        // already known-bad; its error is recorded and branch logic must not
        // pile a second error on top of it.
        if ($ctx->result->hasError($referenced->field)) {
            return true;
        }

        $referenced = $ctx->child(
            $this->field,
            $ctx->valueOf($this->field),
            $ctx->valueOf($this->field) !== null || $ctx->result->hasValue($referenced->field),
        );

        if (!$this->condition->matches($referenced)) {
            return true;
        }

        return !$this->isMissing($ctx->present, $ctx->value);
    }

    /**
     * Determine whether a field counts as missing when it is required.
     *
     * Mirrors the set of values {@see \Lucent\Validation\Constraints\Required}
     * and {@see \Lucent\Validation\Combinators\Optional} treat as missing:
     * absent, null, empty string, or empty array. `false` and `0` count as
     * present.
     *
     * @param bool $present Whether the field key was present.
     * @param mixed $value The field's value.
     * @return bool True when the field is absent or empty.
     */
    private function isMissing(bool $present, mixed $value): bool
    {
        if (!$present || $value === null || $value === '') {
            return true;
        }

        return is_array($value) && $value === [];
    }
}
