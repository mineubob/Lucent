<?php

declare(strict_types=1);

namespace Lucent\Validation\Constraints;

use Closure;
use Lucent\Facades\UUID as UuidFacade;
use Lucent\Validation\Constraint;
use Lucent\Validation\FieldContext;
use Override;

/**
 * Validates that a value is a valid UUID.
 *
 * May be restricted to a specific UUID version.
 */
final class Uuid extends Constraint
{
    /**
     * Create a UUID constraint.
     *
     * @param int|null $version Restrict validation to a specific UUID version, or null for any.
     */
    public function __construct(private readonly ?int $version = null) {}

    /**
     * @return string|Closure(FieldContext): string The default error message.
     */
    #[Override]
    protected function defaultMessage(): string|Closure|null
    {
        return fn(FieldContext $ctx) => "The {$ctx->field} must be a valid UUID.";
    }

    /**
     * Validate that the value is a valid UUID.
     *
     * @param FieldContext $ctx The context of the field being validated.
     * @return bool True if the value is a valid UUID, false otherwise.
     */
    #[Override]
    public function validate(FieldContext $ctx): bool
    {
        return is_string($ctx->value) && UuidFacade::isValid($ctx->value, $this->version);
    }
}