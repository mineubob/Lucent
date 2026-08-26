<?php

declare(strict_types=1);

namespace Lucent\Validation\Combinators;

use Closure;
use Lucent\Validation\Constraint;
use Lucent\Validation\FieldContext;
use Override;

/**
 * A combinator that passes when exactly one wrapped constraint passes.
 *
 * The exclusive-or of {@see Any} and {@see None}: the field is valid only
 * when precisely one of the wrapped constraints matches. If none match, the
 * field fails; if more than one matches, the field also fails. Useful for
 * mutually exclusive alternatives, e.g. "either a phone number or an email,
 * but not both".
 */
final class One extends Constraint
{
    /**
     * @param array<int, Constraint> $constraints The constraints to apply.
     */
    private function __construct(private readonly array $constraints) {}

    /**
     * Create a "one" combinator from one or more constraints.
     *
     * @param Constraint ...$constraints The constraints of which exactly one must pass.
     * @return self A new One instance.
     */
    public static function of(Constraint ...$constraints): self
    {
        return new self($constraints);
    }

    /**
     * No message of its own — the generic "exactly one" message and the
     * matched constraints' messages are recorded directly on the result in
     * {@see validate()}.
     *
     * @return string|\Closure(FieldContext): ?string|null Always null.
     */
    #[Override]
    protected function defaultMessage(): string|\Closure|null
    {
        return null;
    }

    /**
     * Validate that exactly one wrapped constraint passes.
     *
     * Each alternative is validated in isolation. If exactly one passes, the
     * field passes and no errors are recorded. If more than one passes, the
     * generic "must match exactly one" message is recorded first, followed by
     * each matched constraint's message, so the user sees which rules the
     * value matched (and therefore must not both match). If none pass, every
     * failed alternative's message is recorded so the caller sees all
     * acceptable options.
     *
     * @param FieldContext $ctx The context of the field being validated.
     * @return bool True if exactly one constraint passes, false otherwise.
     */
    #[Override]
    public function validate(FieldContext $ctx): bool
    {
        $matched = [];
        $failed = [];

        foreach ($this->constraints as $constraint) {
            if ($constraint->validate($ctx)) {
                $matched[] = $constraint;
            } else {
                $failed[] = $constraint;
            }
        }

        if (count($matched) === 1) {
            return true;
        }

        if (count($matched) > 1) {
            // More than one matched — the field is invalid. Record the
            // generic framing message first, then each matched constraint's
            // message so the user sees which rules the value matched (and
            // therefore must not both match).
            $ctx->result->addError($ctx->field, "The {$ctx->field} must match exactly one of the given rules.");
            foreach ($matched as $constraint) {
                $message = $constraint->message($ctx);
                if ($message !== null) {
                    $ctx->result->addError($ctx->field, $message);
                }
            }
            return false;
        }

        // No alternative matched — record every failed alternative's message
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