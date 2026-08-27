<?php

declare(strict_types=1);

namespace Lucent\Validation\Combinators;

use Closure;
use Lucent\Validation\Constraint;
use Lucent\Validation\FieldContext;
use Override;

/**
 * A combinator that makes a wrapped constraint optional.
 *
 * Present-but-empty values (null, empty string, or empty array) are normalized
 * to null before the inner constraint is applied; absent fields are left
 * untouched so they do not appear in the result. Note that `false` and `0`
 * are **not** treated as empty — they are validated by the inner constraint.
 */
final class Optional extends Constraint
{
    /**
     * Create an optional wrapper around another constraint.
     *
     * @param Constraint $inner The constraint to apply when a value is present.
     */
    public function __construct(private readonly Constraint $inner) {}

    /**
     * Delegate the error message to the wrapped constraint.
     *
     * @return string|Closure(FieldContext): string The inner constraint's message or closure.
     */
    #[Override]
    protected function defaultMessage(): string|Closure|null
    {
        return fn(FieldContext $ctx) => $this->inner->message($ctx);
    }

    /**
     * Validate the wrapped constraint, skipping it when the field is empty.
     *
     * Validation is skipped when the field was not present in the request
     * body, or when its value is null, an empty string, or an empty array.
     * This mirrors the set of values {@see \Lucent\Validation\Constraints\Required}
     * treats as missing, so an optional field may be omitted or left blank.
     * Any other value is validated by the inner constraint.
     *
     * @param FieldContext $ctx The context of the field being validated.
     * @return bool True when the field is empty, otherwise the result of the wrapped constraint.
     */
    #[Override]
    public function validate(FieldContext $ctx): bool
    {
        if (!$ctx->present) {
            return true;
        }

        if ($this->isEmpty($ctx->value)) {
            // Normalize a present-but-empty value to null so the result
            // reflects the documented behaviour of an optional field that was
            // left blank. Absent fields are left untouched so they do not
            // appear in the result.
            $ctx->normalize(null);
            return true;
        }

        return $this->inner->validate($ctx);
    }

    /**
     * Determine whether a value counts as empty.
     *
     * @param mixed $value The value to inspect.
     * @return bool True if the value is null, an empty string, or an empty array.
     */
    private function isEmpty(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }

        return is_array($value) && $value === [];
    }
}
