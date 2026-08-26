<?php

namespace Tests\Support\Concerns;

use Lucent\Http\Message\ServerRequest;
use Lucent\Validation\Constraint;
use Lucent\Validation\Result;
use Lucent\Validation\Validator;

/**
 * Builds PSR-7 requests and runs validation for unit tests of the
 * Lucent\Validation namespace.
 *
 * The constraint tests all follow the same shape: build a POST request with a
 * parsed body, wrap a constraint in a Validator, and validate. This trait
 * centralises that so each test class only declares the field name it uses.
 */
trait BuildsValidationRequests
{
    /**
     * Build a POST request with the given parsed body.
     *
     * @param array $body The parsed request body.
     * @return ServerRequest
     */
    protected function request(array $body): ServerRequest
    {
        return ServerRequest::create('POST', '/')->withParsedBody($body);
    }

    /**
     * Validate a single field against a constraint.
     *
     * @param string $field The field name to validate.
     * @param array $body The parsed request body.
     * @param Constraint $constraint The constraint to apply to the field.
     * @return Result The validation result.
     */
    protected function validateField(string $field, array $body, Constraint $constraint): Result
    {
        return (new Validator([$field => $constraint]))->validate($this->request($body));
    }
}