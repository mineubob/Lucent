<?php

namespace Tests\Support\Stubs;

use Closure;
use Lucent\Validation\Constraint;
use Lucent\Validation\FieldContext;

/**
 * A constraint that records how many times validate() was called.
 *
 * Used to verify short-circuit behaviour in combinators such as All and Any.
 */
class CountingConstraint extends Constraint
{
    public int $calls = 0;

    public function __construct(private readonly bool $passes) {}

    protected function defaultMessage(): string|Closure|null
    {
        return 'counting constraint failed';
    }

    public function validate(FieldContext $ctx): bool
    {
        $this->calls++;
        return $this->passes;
    }
}