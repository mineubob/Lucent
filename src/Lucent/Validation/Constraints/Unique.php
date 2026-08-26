<?php

declare(strict_types=1);

namespace Lucent\Validation\Constraints;

use Closure;
use Lucent\Validation\Constraint;
use Lucent\Validation\FieldContext;
use Override;

/**
 * Validates that a value does not already exist in a data store.
 *
 * The constraint is decoupled from any particular storage backend: it takes a
 * callable that answers whether a conflicting row exists for a given value.
 * This keeps the Validation namespace free of database or model dependencies.
 *
 * ```php
 * new Unique(fn (mixed $value) => User::where('email', $value)->count() > 0);
 * ```
 *
 * For model-backed uniqueness, prefer the convenience factory
 * {@see \Lucent\Model\Model::uniqueConstraint()}, which builds the callable
 * from a model and column and handles ignoring the current row on updates.
 *
 * Empty values (null, empty string, empty array) always pass — presence is the
 * responsibility of the {@see \Lucent\Validation\Constraints\Required}
 * constraint.
 */
final class Unique extends Constraint
{
    /**
     * @var Closure(mixed): bool Returns true when a conflicting row exists.
     */
    private readonly Closure $exists;

    /**
     * Create a unique constraint backed by an existence check.
     *
     * @param Closure(mixed): bool $exists A callable that receives the field
     *        value and returns true when a conflicting row already exists.
     */
    public function __construct(Closure $exists)
    {
        $this->exists = $exists;
    }

    /**
     * @return string|Closure(FieldContext): string The default error message.
     */
    #[Override]
    protected function defaultMessage(): string|Closure|null
    {
        return fn(FieldContext $ctx) => "The {$ctx->field} has already been taken.";
    }

    /**
     * Validate that the value does not already exist.
     *
     * Empty values always pass, deferring presence to the
     * {@see \Lucent\Validation\Constraints\Required} constraint. Otherwise the
     * value is passed to the existence callable; the constraint fails when a
     * conflicting row exists.
     *
     * @param FieldContext $ctx The context of the field being validated.
     * @return bool True if the value is empty or no conflicting row exists, false otherwise.
     */
    #[Override]
    public function validate(FieldContext $ctx): bool
    {
        $value = $ctx->value;

        if ($value === null || $value === '' || $value === []) {
            return true;
        }

        return !call_user_func($this->exists, $value);
    }
}