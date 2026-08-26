<?php

declare(strict_types=1);

namespace Lucent\Validation\Constraints;

use Closure;
use Lucent\Validation\Constraint;
use Lucent\Validation\FieldContext;
use Override;

/**
 * Validates that a value is a valid IP address.
 *
 * May be restricted to IPv4 or IPv6 via the {@see Ip::IPV4} and
 * {@see Ip::IPV6} flags.
 */
final class Ip extends Constraint
{
    /** Restrict validation to IPv4 addresses. */
    public const IPV4 = FILTER_FLAG_IPV4;

    /** Restrict validation to IPv6 addresses. */
    public const IPV6 = FILTER_FLAG_IPV6;

    /**
     * Create an IP address constraint.
     *
     * @param int $flags Optional filter flags, e.g. {@see Ip::IPV4} or {@see Ip::IPV6}.
     */
    public function __construct(private readonly int $flags = 0) {}

    /**
     * @return string|Closure(FieldContext): string The default error message.
     */
    #[Override]
    protected function defaultMessage(): string|Closure|null
    {
        return fn(FieldContext $ctx) => "The {$ctx->field} must be a valid IP address.";
    }

    /**
     * Validate that the value is a valid IP address.
     *
     * @param FieldContext $ctx The context of the field being validated.
     * @return bool True if the value is a valid IP address, false otherwise.
     */
    #[Override]
    public function validate(FieldContext $ctx): bool
    {
        return is_string($ctx->value)
            && filter_var($ctx->value, FILTER_VALIDATE_IP, $this->flags) !== false;
    }
}