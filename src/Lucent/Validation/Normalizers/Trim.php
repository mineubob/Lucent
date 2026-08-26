<?php

declare(strict_types=1);

namespace Lucent\Validation\Normalizers;

use Lucent\Validation\FieldContext;
use Lucent\Validation\NormalizeConstraint;
use Override;

/**
 * A normalizer that trims whitespace from string values.
 */
final class Trim extends NormalizeConstraint
{
    /**
     * Trim whitespace from a string value.
     *
     * Non-string values are returned unchanged.
     *
     * @param FieldContext $ctx The context of the field being normalized.
     * @param mixed $value The raw value to trim.
     * @return mixed The trimmed string, or the original value if not a string.
     */
    #[Override]
    public function normalize(FieldContext $ctx, mixed $value): mixed
    {
        if (is_string($value)) {
            return trim($value);
        }

        return $value;
    }
}
