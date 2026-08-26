<?php

declare(strict_types=1);

namespace Lucent\Validation\Combinators;

use Closure;
use Lucent\Validation\Concerns\RecordsConstraintFailure;
use Lucent\Validation\Constraint;
use Lucent\Validation\FieldContext;
use Override;

/**
 * A combinator that passes when at least one wrapped constraint passes.
 *
 * If none pass, every failed alternative's message is recorded on the field,
 * so the caller sees all acceptable options.
 */
final class Any extends Constraint
{
    use RecordsConstraintFailure;

    /**
     * @param array<int, Constraint> $constraints The constraints to apply.
     */
    private function __construct(private readonly array $constraints) {}

    /**
     * Create an "any" combinator from one or more constraints.
     *
     * @param Constraint ...$constraints The constraints of which at least one must pass.
     * @return self A new Any instance.
     */
    public static function of(Constraint ...$constraints): self
    {
        return new self($constraints);
    }

    /**
     * No message of its own — failed alternatives' messages are recorded
     * directly on the result.
     *
     * @return string|\Closure(FieldContext): ?string|null Always null.
     */
    #[Override]
    protected function defaultMessage(): string|\Closure|null
    {
        return null;
    }

    /**
     * Validate that at least one wrapped constraint passes.
     *
     * Each alternative is validated in isolation. If an alternative passes,
     * its internal errors are rolled back and the result is left clean. If
     * none pass, every failed alternative's message is left on the result, so
     * the caller sees all acceptable options.
     *
     * @param FieldContext $ctx The context of the field being validated.
     * @return bool True if at least one constraint passes, false otherwise.
     */
    #[Override]
    public function validate(FieldContext $ctx): bool
    {
        $snapshot = $ctx->result->snapshotErrors();

        foreach ($this->constraints as $constraint) {
            if ($this->recordConstraintFailure($constraint, $ctx)) {
                $ctx->result->restoreErrors($snapshot);
                return true;
            }
        }

        return false;
    }
}
