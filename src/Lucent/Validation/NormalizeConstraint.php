<?php

declare(strict_types=1);

namespace Lucent\Validation;

use Override;

/**
 * Base class for normalizers.
 *
 * A normalizer transforms a field's value (e.g. trimming whitespace) without
 * ever failing validation. Subclasses implement {@see normalize()} to return
 * the transformed value, which is stored on the result via the context.
 *
 * Normalizers are constraints in the sense that they participate in the
 * validation pipeline, but they always pass and never record an error.
 */
abstract class NormalizeConstraint extends Constraint
{
    /**
     * Normalizers never produce an error message.
     *
     * A normalizer always passes, so its message is never requested in normal
     * flow. Returning null signals that no error should be recorded, which is
     * safe even if {@see Constraint::message()} is called on a normalizer.
     *
     * @return string|\Closure(FieldContext): ?string|null Always null.
     */
    #[Override]
    protected function defaultMessage(): string|\Closure|null
    {
        return null;
    }

    /**
     * Normalizers always pass.
     *
     * Applies {@see normalize()} to the current value and stores the result
     * on the context, then returns true.
     *
     * @param FieldContext $ctx The context of the field being normalized.
     * @return bool Always true.
     */
    #[Override]
    public function validate(FieldContext $ctx): bool
    {
        $ctx->normalize($this->normalize($ctx, $ctx->value));
        return true;
    }

    /**
     * Transform a raw value.
     *
     * @param FieldContext $ctx The context of the field being normalized.
     * @param mixed $value The raw value to transform.
     * @return mixed The transformed value.
     */
    abstract public function normalize(FieldContext $ctx, mixed $value): mixed;
}