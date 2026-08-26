<?php

declare(strict_types=1);

namespace Lucent\Validation\Constraints;

use Closure;
use Lucent\Validation\Constraint;
use Lucent\Validation\FieldContext;
use Override;

final class Present extends Constraint
{
    /**
     * @return string|Closure(FieldContext): string The default error message.
     */
    #[Override]
    protected function defaultMessage(): string|Closure|null
    {
        return fn(FieldContext $ctx) => "The {$ctx->field} field must be present.";
    }

    /**
     * Validate that the field key was present in the request body.
     *
     * Unlike {@see Required}, this only checks that the key exists — the
     * value may be null, an empty string, or false and still pass. This is
     * useful for booleans and checkboxes where an explicit `false` or `0` is
     * a valid submission but the key must still be supplied.
     *
     * @param FieldContext $ctx The context of the field being validated.
     * @return bool True if the field was present, false otherwise.
     */
    #[Override]
    public function validate(FieldContext $ctx): bool
    {
        return $ctx->present;
    }
}