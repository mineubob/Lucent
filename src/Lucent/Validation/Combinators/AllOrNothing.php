<?php

declare(strict_types=1);

namespace Lucent\Validation\Combinators;

use Closure;
use Lucent\Validation\Concerns\RecordsConstraintFailure;
use Lucent\Validation\Constraint;
use Lucent\Validation\FieldContext;
use Override;

/**
 * A combinator that requires a group of fields to be provided together.
 *
 * Either every field in the group is present, or none are. When all are
 * present, each is validated by its own constraint; when none are present,
 * the group passes and is left untouched. A partial group (some fields
 * present, some absent) fails.
 *
 * This is useful for a set of related optional fields that must be supplied
 * as a unit — for example a `billing_address` group where all sub-fields are
 * required together.
 *
 * ```php
 * AllOrNothing::of([
 *     'street' => new Required(),
 *     'city'   => new Required(),
 * ]);
 * ```
 */
final class AllOrNothing extends Constraint
{
    use RecordsConstraintFailure;

    /**
     * @param array<string, Constraint> $constraints Constraints keyed by field name.
     */
    private function __construct(private readonly array $constraints) {}

    /**
     * Create an "all or nothing" combinator from a map of constraints.
     *
     * @param array<string, Constraint> $constraints Constraints keyed by field name.
     * @return self A new AllOrNothing instance.
     */
    public static function of(array $constraints): self
    {
        return new self($constraints);
    }

    /**
     * @return string|\Closure(FieldContext): ?string|null The default error message.
     */
    #[Override]
    protected function defaultMessage(): string|\Closure|null
    {
        return fn(FieldContext $ctx) => $ctx->childFailed
            ? null
            : "The {$ctx->field} fields must be provided together.";
    }

    /**
     * Validate that the group of fields is all present or all absent.
     *
     * Fails when the value is not an array, when only some of the group's
     * fields are present, or when any present field fails its constraint.
     * When a child fails, its specific error is already recorded on the
     * result at its dotted path, so {@see defaultMessage()} returns null to
     * avoid a redundant generic error.
     *
     * @param FieldContext $ctx The context of the field being validated.
     * @return bool True if the group is all present (and valid) or all absent.
     */
    #[Override]
    public function validate(FieldContext $ctx): bool
    {
        $ctx->childFailed = false;

        if (!is_array($ctx->value)) {
            return false;
        }

        $present = [];
        foreach (array_keys($this->constraints) as $name) {
            $present[$name] = array_key_exists($name, $ctx->value);
        }

        $presentCount = count(array_filter($present));

        // All absent: the group is optional. Normalize to null so the result
        // reflects that the group was not provided (mirroring Optional).
        if ($presentCount === 0) {
            $ctx->normalize(null);
            return true;
        }

        // Partial group: some fields present, some absent.
        if ($presentCount !== count($this->constraints)) {
            return false;
        }

        // All present: validate each field.
        $ctx->result->ensureContainer($ctx->field);

        foreach ($this->constraints as $name => $constraint) {
            $child = $ctx->child($name, $ctx->value[$name], true);
            $child->seedRaw();

            if (!$this->recordConstraintFailure($constraint, $child)) {
                $ctx->childFailed = true;
            }
        }

        return !$ctx->childFailed;
    }
}