<?php

declare(strict_types=1);

namespace Lucent\Validation;

/**
 * Base class for all validation constraints.
 *
 * A constraint is a single, composable validation rule applied to one field
 * of a data payload. Subclasses implement {@see validate()} to decide
 * whether a value is acceptable, and {@see defaultMessage()} to provide a
 * human-readable error message when validation fails.
 *
 * Constraints are passed to a {@see Validator}, which applies them to a data
 * payload and collects the results into a {@see Result}.
 */
abstract class Constraint
{
    /**
     * Optional custom message, either a literal string or a closure that
     * receives the {@see FieldContext} and returns a string.
     *
     * @var string|\Closure(FieldContext): string|null
     */
    private string|\Closure|null $customMessage = null;

    /**
     * Whether the custom message overrides child-recorded errors.
     *
     * @var bool
     */
    private bool $overridesChildErrors = false;

    /**
     * Override the default error message for this constraint.
     *
     * The message may be a plain string or a closure that receives the
     * {@see FieldContext} and returns the message string, allowing messages
     * to be built dynamically from the field name or value.
     *
     * For combinators ({@see \Lucent\Validation\Combinators\Shape},
     * {@see \Lucent\Validation\Combinators\Cases}, …) the message replaces
     * the combinator's own generic wording. It does **not** mask the
     * specific error a child already recorded — unless `override` is true,
     * which forces the custom message in every failure path.
     *
     * @param string|\Closure(FieldContext): string $message The custom message or a closure producing it.
     * @param bool $override When true, the message is used even when a child
     *        constraint already recorded its specific error.
     * @return static The current instance for method chaining.
     */
    final public function withMessage(string|\Closure $message, bool $override = false): static
    {
        $this->customMessage = $message;
        $this->overridesChildErrors = $override;
        return $this;
    }

    /**
     * Resolve the error message for a given field context.
     *
     * Returns the custom message if one was set via {@see withMessage()},
     * otherwise falls back to {@see defaultMessage()}. Closures are invoked
     * with the supplied context and may themselves return null to signal
     * "no message".
     *
     * When the context's {@see FieldContext::$childFailed} flag is set, a
     * child constraint already recorded its specific error, so this
     * constraint's message is suppressed — the child's pinpointed error
     * stands alone. The suppression is bypassed only by a custom message
     * that explicitly requested the override.
     *
     * @param FieldContext $ctx The context of the field being validated.
     * @return string|null The resolved error message, or null to skip.
     */
    final public function message(FieldContext $ctx): ?string
    {
        // When a child failed, its specific error stands: this constraint's
        // message is suppressed unless a custom message explicitly requested
        // the override. The override needs a custom message to substitute;
        // the fall-through path below resolves closures uniformly.
        if ($ctx->childFailed && !($this->overridesChildErrors && $this->customMessage !== null)) {
            return null;
        }

        $message = $this->customMessage ?? $this->defaultMessage();

        if ($message === null) {
            return null;
        }

        if ($message instanceof \Closure) {
            return call_user_func($message, $ctx);
        }

        return $message;
    }

    /**
     * Provide the default error message for this constraint.
     *
     * May return a plain string, a closure that receives the
     * {@see FieldContext} and returns the message string, or null to signal
     * that no message should be reported (used when a child constraint has
     * already recorded its specific error).
     *
     * @return string|\Closure(FieldContext): ?string|null The default message, a closure producing it, or null to skip.
     */
    abstract protected function defaultMessage(): string|\Closure|null;

    /**
     * Declare the fields this constraint reads from its siblings.
     *
     * Advisory metadata used by container constraints (notably
     * {@see \Lucent\Validation\Combinators\Shape}) to order validation: any
     * child that declares references is validated **after** children with no
     * references, so a referenced field is always validated — and normalized —
     * before a constraint that depends on it, regardless of declaration
     * order.
     *
     * Names are bare leaf names resolved relative to the constraint's own
     * level (the same naming the cross-field getter accepts). The default is
     * an empty array: most constraints are single-field.
     *
     * @return list<string> The referenced sibling field names.
     */
    public function referencedFields(): array
    {
        return [];
    }

    /**
     * Validate a field value.
     *
     * Implementations inspect the value exposed by the context and return
     * whether it satisfies the rule. They may also call
     * {@see FieldContext::normalize()} to transform the value (e.g. casting a
     * numeric string to a number) before it is stored in the result.
     *
     * @param FieldContext $ctx The context of the field being validated.
     * @return bool True if the value is valid, false otherwise.
     */
    abstract public function validate(FieldContext $ctx): bool;
}
