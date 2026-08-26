<?php

declare(strict_types=1);

namespace Lucent\Validation\Concerns;

use Lucent\Validation\FieldContext;

trait TracksFailure
{
    /**
     * Delegate the error message to the constraint that failed.
     *
     * The failing constraint is read from the field context, where it was
     * recorded by {@see \Lucent\Validation\Combinators\All::validate()}.
     * Returns null when no constraint failed (e.g. the composite passed, or
     * the failing constraint itself reports no message), so the parent skips
     * adding a redundant generic error.
     *
     * @return string|\Closure(FieldContext): ?string|null The failing constraint's message or closure.
     */
    #[\Override]
    protected function defaultMessage(): string|\Closure|null
    {
        return fn(FieldContext $ctx) => $ctx->failedConstraint?->message($ctx);
    }
}
