<?php

declare(strict_types=1);

namespace Lucent\Validation\Combinators;

use Closure;
use Lucent\Validation\Constraint;
use Lucent\Validation\FieldContext;
use Lucent\Validation\Result;
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
     * Each alternative is validated in isolation against a fresh, throwaway
     * {@see Result}, so a losing branch's errors *and* values never leak into
     * the final result. If exactly one passes, its result is committed via
     * {@see Result::merge()} and the field passes. If more than one passes,
     * the generic "must match exactly one" message is recorded first,
     * followed by each matched constraint's message, so the user sees which
     * rules the value matched (and therefore must not both match). If none
     * pass, a single generic message is recorded so the caller sees one clear
     * error rather than a pile-up of every failed alternative's errors.
     *
     * @param FieldContext $ctx The context of the field being validated.
     * @return bool True if exactly one constraint passes, false otherwise.
     */
    #[Override]
    public function validate(FieldContext $ctx): bool
    {
        $matched = [];
        $branchResults = [];
        $failed = [];

        foreach ($this->constraints as $constraint) {
            [$passed, $branch] = $ctx->branch($constraint);

            if ($passed) {
                $matched[] = $constraint;
                $branchResults[] = $branch;
            } else {
                $failed[] = [$constraint, $branch];
            }
        }

        if (count($matched) === 1) {
            // Exactly one alternative passed — commit its result so the
            // winning branch's normalized values are preserved.
            $ctx->result->merge($branchResults[0]);
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

        // No alternative matched — record the generic framing message first,
        // then surface each failed alternative's specific errors so the
        // caller sees the rules that were expected. Simple constraints (e.g.
        // Length) report their message at the field path; composite ones
        // (e.g. Shape) record child errors at their dotted paths.
        $ctx->result->addError($ctx->field, "The {$ctx->field} must match exactly one of the given rules.");
        foreach ($failed as [$constraint, $branch]) {
            $ctx->result->merge($branch);

            $message = $constraint->message($ctx);
            if ($message !== null) {
                $ctx->result->addError($ctx->field, $message);
            }
        }
        return false;
    }
}