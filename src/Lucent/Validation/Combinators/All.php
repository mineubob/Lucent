<?php

declare(strict_types=1);

namespace Lucent\Validation\Combinators;

use Lucent\Validation\Concerns\TracksFailure;
use Lucent\Validation\Constraint;
use Lucent\Validation\FieldContext;

/**
 * A combinator that passes only when every wrapped constraint passes.
 *
 * Stops at the first failing constraint, whose message is used for the error.
 */
final class All extends Constraint
{
    use TracksFailure;

    /**
     * @param array<int, Constraint> $constraints The constraints to apply.
     */
    private function __construct(private readonly array $constraints) {}

    /**
     * Create an "all" combinator from one or more constraints.
     *
     * @param Constraint ...$constraints The constraints that must all pass.
     * @return self A new All instance.
     */
    public static function of(Constraint ...$constraints): self
    {
        return new self($constraints);
    }

    /**
     * Validate that every wrapped constraint passes.
     *
     * Stops at the first failing constraint and records it as the failure
     * used to build the error message.
     *
     * @param FieldContext $ctx The context of the field being validated.
     * @return bool True if all constraints pass, false otherwise.
     */
    #[\Override]
    public function validate(FieldContext $ctx): bool
    {
        foreach ($this->constraints as $constraint) {
            if (!$constraint->validate($ctx)) {
                $ctx->failedConstraint = $constraint;
                return false;
            }
        }

        return true;
    }
}
