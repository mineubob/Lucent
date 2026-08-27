<?php

declare(strict_types=1);

namespace Lucent\Validation\Concerns;

use Lucent\Validation\Constraint;
use Lucent\Validation\FieldContext;

/**
 * Shared helper for validating a constraint and recording its failure.
 *
 * Encapsulates the common sequence of running a constraint against a field
 * context, resolving its message, and recording the error on the result when
 * one exists. Used by the {@see \Lucent\Validation\Validator} and the shape
 * combinators to avoid duplicating the validate → message → addError flow.
 */
trait RecordsConstraintFailure
{
    /**
     * Validate a constraint and record its error if it fails.
     *
     * Runs the constraint against the context. On failure, resolves the
     * constraint's message and records it on the result at the context's
     * field path, unless the message is null (meaning a child already
     * recorded its specific error).
     *
     * @param Constraint $constraint The constraint to validate.
     * @param FieldContext $ctx The context of the field being validated.
     * @return bool True if the constraint passed, false otherwise.
     */
    protected function recordConstraintFailure(Constraint $constraint, FieldContext $ctx): bool
    {
        if ($constraint->validate($ctx)) {
            return true;
        }

        $message = $constraint->message($ctx);
        if ($message !== null) {
            $ctx->result->addError($ctx->field, $message);
        }

        return false;
    }
}