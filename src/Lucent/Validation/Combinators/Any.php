<?php

declare(strict_types=1);

namespace Lucent\Validation\Combinators;

use Closure;
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
     * Each alternative is validated in isolation against a fresh, throwaway
     * {@see Result}, so a losing branch's errors *and* values never leak into
     * the final result. If an alternative passes, its result is committed via
     * {@see Result::merge()} and the field passes. If none pass, every failed
     * alternative's message is recorded on the result, so the caller sees all
     * acceptable options.
     *
     * @param FieldContext $ctx The context of the field being validated.
     * @return bool True if at least one constraint passes, false otherwise.
     */
    #[Override]
    public function validate(FieldContext $ctx): bool
    {
        $failed = [];

        foreach ($this->constraints as $constraint) {
            [$passed, $branch] = $ctx->branch($constraint);

            if ($passed) {
                // This alternative passed — commit its result so the winning
                // branch's normalized values are preserved, and discard the
                // failed branches' errors.
                $ctx->result->merge($branch);
                return true;
            }

            $failed[] = $constraint;
        }

        // No alternative passed — record every failed alternative's message
        // so the caller sees all acceptable options.
        foreach ($failed as $constraint) {
            $message = $constraint->message($ctx);
            if ($message !== null) {
                $ctx->result->addError($ctx->field, $message);
            }
        }

        return false;
    }
}
