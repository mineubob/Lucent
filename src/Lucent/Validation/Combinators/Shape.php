<?php

declare(strict_types=1);

namespace Lucent\Validation\Combinators;

use Closure;
use Lucent\Validation\Concerns\RecordsConstraintFailure;
use Lucent\Validation\Constraint;
use Lucent\Validation\FieldContext;
use Override;

/**
 * A combinator that validates an array value as an object or a tuple.
 *
 * Shape has two forms, selected by factory:
 *
 * - {@see object()} validates a map of named sub-fields, each with its own
 *   constraint. Sub-field errors and values are namespaced under the parent
 *   field's dotted path (e.g. `user.name`).
 * - {@see tuple()} validates a fixed-length, positional list where each
 *   position has its own constraint. Element errors and values are namespaced
 *   by index (e.g. `pair.0`).
 *
 * ```php
 * Shape::object(['name' => new Required(), 'email' => new Email()]);
 * Shape::tuple(new Numeric(), new Length(min: 2));
 * ```
 */
final class Shape extends Constraint
{
    use RecordsConstraintFailure;

    /**
     * Create a shape.
     *
     * @param array<int|string, Constraint> $constraints Constraints keyed by
     *        sub-field name (object) or position (tuple).
     * @param bool $isTuple Whether the shape is a tuple.
     */
    private function __construct(
        private readonly array $constraints,
        private readonly bool $isTuple,
    ) {}

    /**
     * Create an object shape from a set of named sub-constraints.
     *
     * @param array<string, Constraint> $constraints Constraints keyed by sub-field name.
     * @return self A new object Shape instance.
     */
    public static function object(array $constraints): self
    {
        return new self($constraints, false);
    }

    /**
     * Create a tuple shape from a set of positional constraints.
     *
     * The tuple has exactly as many positions as constraints. Each position
     * is validated by its own constraint.
     *
     * @param Constraint ...$constraints Constraints applied positionally.
     * @return self A new tuple Shape instance.
     */
    public static function tuple(Constraint ...$constraints): self
    {
        return new self($constraints, true);
    }

    /**
     * @return string|\Closure(FieldContext): ?string|null The default error message.
     */
    #[Override]
    protected function defaultMessage(): string|\Closure|null
    {
        return fn(FieldContext $ctx) => $ctx->childFailed
            ? null
            : ($this->isTuple
                ? "The {$ctx->field} must be an array with exactly " . count($this->constraints) . ' elements.'
                : "The {$ctx->field} must be an object.");
    }

    /**
     * Validate the value as an object or tuple.
     *
     * Fails (returns false) when the value is not an array (or, for an object
     * shape, not an object), when a tuple's length does not match its
     * constraints, or when any child constraint fails. When a child fails,
     * its specific error is already recorded on the result at its dotted
     * path, so {@see defaultMessage()} returns null to avoid a redundant
     * generic error.
     *
     * @param FieldContext $ctx The context of the field being validated.
     * @return bool True if the value has the expected shape and all children pass.
     */
    #[Override]
    public function validate(FieldContext $ctx): bool
    {
        $ctx->childFailed = false;

        $value = $this->normalizeValue($ctx->value);

        if ($value === null) {
            return false;
        }

        if ($this->isTuple) {
            return $this->validateTuple($ctx, $value);
        }

        return $this->validateObject($ctx, $value);
    }

    /**
     * Coerce the raw value into an array, or null if it has the wrong shape.
     *
     * @param mixed $value The raw value.
     * @return array<int|string, mixed>|null The value as an array, or null.
     */
    private function normalizeValue(mixed $value): array|null
    {
        if (is_array($value)) {
            return $value;
        }

        if (!$this->isTuple && is_object($value)) {
            return get_object_vars($value);
        }

        return null;
    }

    /**
     * Validate a tuple value against its positional constraints.
     *
     * @param FieldContext $ctx The context of the field being validated.
     * @param array<int|string, mixed> $value The tuple value.
     * @return bool False when the length does not match or a child fails.
     */
    private function validateTuple(FieldContext $ctx, array $value): bool
    {
        // A tuple is a positional list. An associative array is a map/object
        // and is rejected, as is a list whose length does not match the
        // number of constraints.
        if (!array_is_list($value) || count($value) !== count($this->constraints)) {
            return false;
        }

        $ctx->result->ensureContainer($ctx->field);

        foreach ($this->constraints as $index => $constraint) {
            $child = $ctx->child($index, $value[$index], true);
            $child->seedRaw();

            if (!$this->recordConstraintFailure($constraint, $child)) {
                $ctx->childFailed = true;
            }
        }

        return !$ctx->childFailed;
    }

    /**
     * Validate an object value against its named sub-constraints.
     *
     * @param FieldContext $ctx The context of the field being validated.
     * @param array<int|string, mixed> $value The object value.
     * @return bool False when a child fails, true otherwise.
     */
    private function validateObject(FieldContext $ctx, array $value): bool
    {
        // Declare the field as a container, then seed each present declared
        // sub-field with its raw value. Undeclared keys are never written, so
        // they are excluded from the result. Each present sub-field is then
        // validated and may overwrite its value via normalization.
        $ctx->result->ensureContainer($ctx->field);

        foreach ($this->constraints as $name => $constraint) {
            $present = array_key_exists($name, $value);
            $child = $ctx->child($name, $present ? $value[$name] : null, $present);

            if ($present) {
                $child->seedRaw();
            }

            if (!$this->recordConstraintFailure($constraint, $child)) {
                $ctx->childFailed = true;
            }
        }

        return !$ctx->childFailed;
    }
}
