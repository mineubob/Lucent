<?php

declare(strict_types=1);

namespace Lucent\Validation\Constraints;

use Closure;
use Lucent\Validation\Constraint;
use Lucent\Validation\FieldContext;
use Override;

/**
 * Validates that a field contains a successfully uploaded file.
 */
final class UploadedFile extends Constraint
{
    /**
     * @return string|Closure(FieldContext): string The default error message.
     */
    #[Override]
    protected function defaultMessage(): string|Closure|null
    {
        return fn(FieldContext $ctx) => "The {$ctx->field} must be a valid file.";
    }

    /**
     * Validate that the field contains a successfully uploaded file.
     *
     * @param FieldContext $ctx The context of the field being validated.
     * @return bool True if a file was uploaded without error, false otherwise.
     */
    #[Override]
    public function validate(FieldContext $ctx): bool
    {
        $file = $ctx->file();
        return $file !== null && $file->getError() === UPLOAD_ERR_OK;
    }
}
