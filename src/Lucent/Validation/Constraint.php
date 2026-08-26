<?php

declare(strict_types=1);

namespace Lucent\Validation;

/**
 * Base class for all validation constraints.
 *
 * A constraint is a single, composable validation rule applied to one field
 * of an incoming request. Subclasses implement {@see validate()} to decide
 * whether a value is acceptable, and {@see defaultMessage()} to provide a
 * human-readable error message when validation fails.
 *
 * Constraints are passed to a {@see Validator}, which applies them to a PSR-7
 * request and collects the results into a {@see Result}.
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
     * Override the default error message for this constraint.
     *
     * The message may be a plain string or a closure that receives the
     * {@see FieldContext} and returns the message string, allowing messages
     * to be built dynamically from the field name or value.
     *
     * @param string|\Closure(FieldContext): string $message The custom message or a closure producing it.
     * @return static The current instance for method chaining.
     */
    final public function withMessage(string|\Closure $message): static
    {
        $this->customMessage = $message;
        return $this;
    }

    /**
     * Resolve the error message for a given field context.
     *
     * Returns the custom message if one was set via {@see withMessage()},
     * otherwise falls back to {@see defaultMessage()}. Closures are invoked
     * with the supplied context. Returns null when there is no message to
     * report — used by combinators whose child constraints already recorded
     * their specific errors on the result.
     *
     * @param FieldContext $ctx The context of the field being validated.
     * @return string|null The resolved error message, or null to skip.
     */
    final public function message(FieldContext $ctx): ?string
    {
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
