<?php

declare(strict_types=1);

namespace Lucent\Validation\Constraints;

use Closure;
use Lucent\Http\Message\Uri as MessageUri;
use Lucent\Validation\Constraint;
use Lucent\Validation\FieldContext;
use Override;

/**
 * Validates that a value is a valid URI.
 *
 * On success the value is normalized to a {@see MessageUri} instance.
 */
final class Uri extends Constraint
{
    /**
     * Create a URI constraint.
     *
     * @param int $flags Validation flags for {@see MessageUri::isValid()}.
     */
    public function __construct(private readonly int $flags = MessageUri::VALIDATE_DEFAULT) {}

    /**
     * @return string|Closure(FieldContext): string The default error message.
     */
    #[Override]
    protected function defaultMessage(): string|Closure|null
    {
        return fn(FieldContext $ctx) => "The {$ctx->field} must be a valid URI.";
    }

    /**
     * Validate that the value is a valid URI.
     *
     * On success the value is normalized to a {@see MessageUri} instance.
     *
     * @param FieldContext $ctx The context of the field being validated.
     * @return bool True if the value is a valid URI, false otherwise.
     */
    #[Override]
    public function validate(FieldContext $ctx): bool
    {
        if (!is_string($ctx->value) || !MessageUri::isValid($ctx->value, $this->flags)) {
            return false;
        }

        $ctx->normalize(MessageUri::fromString($ctx->value));
        return true;
    }
}
