<?php

declare(strict_types=1);

namespace Lucent\Validation\Combinators;

use Closure;
use Lucent\Validation\Constraint;
use Lucent\Validation\FieldContext;
use Override;

/**
 * A combinator that passes only when none of the wrapped constraints pass.
 *
 * The inverse of {@see Any}: the field is valid only when every wrapped
 * constraint fails. Useful for "must not be any of these" rules. If any
 * wrapped constraint passes, the field fails.
 */
final class None extends Constraint
{
    /**
     * @param array<int, Constraint> $constraints The constraints that must all fail.
     */
    private function __construct(private readonly array $constraints) {}

    /**
     * Create a "none" combinator from one or more constraints.
     *
     * @param Constraint ...$constraints The constraints that must all fail.
     * @return self A new None instance.
     */
    public static function of(Constraint ...$constraints): self
    {
        return new self($constraints);
    }

    /**
     * @return string|\Closure(FieldContext): ?string|null The default error message.
     */
    #[Override]
    protected function defaultMessage(): string|\Closure|null
    {
        return fn(FieldContext $ctx) => $ctx->childFailed
            ? null
            : "The {$ctx->field} must not match any of the given rules.";
    }

    /**
     * Validate that none of the wrapped constraints pass.
     *
     * Each constraint is validated in isolation. A failing constraint is the
     * desired outcome, so it is simply skipped. If a constraint passes, the
     * field fails: the generic "must not match" message is recorded first,
     * followed by the matched constraint's message, so the user sees which
     * rule the value matched (and therefore must not match).
     *
     * @param FieldContext $ctx The context of the field being validated.
     * @return bool True if none of the constraints pass, false otherwise.
     */
    #[Override]
    public function validate(FieldContext $ctx): bool
    {
        $ctx->childFailed = false;

        foreach ($this->constraints as $constraint) {
            if ($constraint->validate($ctx)) {
                // A constraint matched — the field is invalid. Record the
                // generic framing message first, then the matched
                // constraint's message so the user sees which rule the value
                // matched (and therefore must not match).
                $message = $constraint->message($ctx);
                if ($message !== null) {
                    $ctx->result->addError($ctx->field, "The {$ctx->field} must not match any of the given rules.");
                    $ctx->result->addError($ctx->field, $message);
                }
                $ctx->childFailed = true;
                return false;
            }
        }

        return true;
    }
}