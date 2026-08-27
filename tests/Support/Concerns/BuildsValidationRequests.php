<?php

namespace Tests\Support\Concerns;

use Lucent\Http\Message\UploadedFile;
use Lucent\Validation\Constraint;
use Lucent\Validation\Result;
use Lucent\Validation\Validator;

/**
 * Runs validation against raw data for unit tests of the
 * Lucent\Validation namespace.
 *
 * The constraint tests all follow the same shape: wrap a constraint in a
 * Validator and validate a raw data array. This trait centralises that so
 * each test class only declares the field name it uses.
 */
trait BuildsValidationRequests
{
    /**
     * Validate a single field against a constraint.
     *
     * @param string $field The field name to validate.
     * @param array $body The data payload to validate.
     * @param Constraint $constraint The constraint to apply to the field.
     * @return Result The validation result.
     */
    protected function validateField(string $field, array $body, Constraint $constraint): Result
    {
        return (new Validator([$field => $constraint]))->validate($body);
    }

    /**
     * Validate a raw value against a top-level constraint.
     *
     * Passes the value straight to the {@see Validator} as the whole payload,
     * for constraints that operate on the body itself (e.g. a top-level
     * {@see \Lucent\Validation\Combinators\Each} or
     * {@see \Lucent\Validation\Combinators\Shape}, or a scalar constraint
     * over a plain value).
     *
     * @param mixed $value The raw value to validate.
     * @param Constraint $constraint The top-level constraint to apply.
     * @return Result The validation result.
     */
    protected function validateValue(mixed $value, Constraint $constraint): Result
    {
        return (new Validator($constraint))->validate($value);
    }

    /**
     * Build an uploaded file for file-constraint tests.
     *
     * @param int $error The upload error code (defaults to UPLOAD_ERR_OK).
     * @return UploadedFile A successfully-uploaded file by default.
     */
    protected function file(int $error = UPLOAD_ERR_OK): UploadedFile
    {
        return new UploadedFile('path/to/file.txt', 10, $error, 'file.txt');
    }
}