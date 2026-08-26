<?php

declare(strict_types=1);

namespace Lucent\Validation\Combinators;

use Lucent\Validation\Concerns\RecordsConstraintFailure;
use Lucent\Validation\Constraint;
use Lucent\Validation\FieldContext;
use Override;

/**
 * A combinator that validates every element of an array value.
 *
 * Each treats the field's value as a list and applies the wrapped constraint
 * to every element. Element errors and normalized values are namespaced under
 * the parent field's dotted path with the element index (e.g. `items.0`).
 *
 * ```php
 * new Each(new Numeric());          // array of numbers
 * new Each(new Shape([...]));       // array of objects
 * ```
 *
 * **Resource note:** Each processes the entire array, so memory grows linearly
 * with the input size. Consumers should bound input size (e.g. via
 * {@see \Lucent\Validation\Constraints\Length} on the array, a middleware
 * body-size limit, or the `$maxItems` constructor argument) to prevent a
 * memory-exhaustion DoS from an unbounded request array.
 */
final class Each extends Constraint
{
    use RecordsConstraintFailure;

    /**
     * Create an "each" combinator around a single constraint.
     *
     * @param Constraint $inner The constraint applied to every element.
     * @param int|null $maxItems Optional maximum number of elements. When set,
     *        validation fails if the array has more elements than this, before
     *        any element is processed.
     */
    public function __construct(
        private readonly Constraint $inner,
        private readonly ?int $maxItems = null,
    ) {}

    /**
     * @return string|\Closure(FieldContext): ?string|null The default error message.
     */
    #[Override]
    protected function defaultMessage(): string|\Closure|null
    {
        return fn(FieldContext $ctx) => $ctx->childFailed
            ? null
            : ($this->maxItems !== null && is_array($ctx->value) && count($ctx->value) > $this->maxItems
                ? "The {$ctx->field} must contain at most {$this->maxItems} items."
                : "The {$ctx->field} must be an array.");
    }

    /**
     * Validate every element of the array value.
     *
     * Fails (returns false) when the value is not an array, when it exceeds
     * the configured `$maxItems` bound, or when any element fails its
     * constraint. When an element fails, its specific error is already
     * recorded on the result at its dotted path, so {@see defaultMessage()}
     * returns null to avoid a redundant generic error.
     *
     * @param FieldContext $ctx The context of the field being validated.
     * @return bool True if the value is an array and every element passes.
     */
    #[Override]
    public function validate(FieldContext $ctx): bool
    {
        $ctx->childFailed = false;

        if (!is_array($ctx->value)) {
            return false;
        }

        if ($this->maxItems !== null && count($ctx->value) > $this->maxItems) {
            return false;
        }

        // Declare the field as a container so only validated elements are
        // included in the result; each element is seeded with its raw value
        // so it survives even when the inner constraint does not normalize.
        $ctx->result->ensureContainer($ctx->field);

        foreach ($ctx->value as $index => $element) {
            $child = $ctx->child($index, $element, true);
            $child->seedRaw();

            if (!$this->recordConstraintFailure($this->inner, $child)) {
                $ctx->childFailed = true;
            }
        }

        return !$ctx->childFailed;
    }
}