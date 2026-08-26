<?php

declare(strict_types=1);

namespace Lucent\Validation;

use Lucent\Validation\Combinators\Shape;
use Lucent\Validation\Concerns\RecordsConstraintFailure;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Applies a set of constraints to a PSR-7 request.
 *
 * The Validator is constructed with either a single top-level constraint
 * (typically a {@see Shape} for an object body or an {@see \Lucent\Validation\Combinators\Each}
 * for an array body) or a flat map of constraints keyed by field name, which
 * is sugar for a top-level {@see Shape}. Calling {@see validate()} runs the
 * constraint(s) against the request body and returns a {@see Result}
 * containing any errors and validated values.
 */
final class Validator
{
    use RecordsConstraintFailure;

    /**
     * The top-level constraint applied to the request body.
     */
    private readonly Constraint $constraint;

    /**
     * Create a validator from a top-level constraint or a flat constraint map.
     *
     * @param Constraint|array<string, Constraint> $constraints A single top-level
     *        constraint, or a map of constraints keyed by field name (wrapped in a Shape).
     * @throws \InvalidArgumentException If any value is not a {@see Constraint} instance.
     */
    public function __construct(Constraint|array $constraints)
    {
        if ($constraints instanceof Constraint) {
            $this->constraint = $constraints;
            return;
        }

        foreach ($constraints as $constraint) {
            if (!($constraint instanceof Constraint)) {
                throw new \InvalidArgumentException('Constraint must be an instance of ' . Constraint::class);
            }
        }

        $this->constraint = Shape::object($constraints);
    }

    /**
     * Validate a request against the configured constraints.
     *
     * The top-level constraint is applied to the request body. Failed
     * constraints record an error message on the result; successful
     * constraints may normalize the value, which is stored on the result.
     *
     * @param ServerRequestInterface $request The request to validate.
     * @return Result The validation result containing errors and validated values.
     */
    public function validate(ServerRequestInterface $request): Result
    {
        $result = new Result();
        $files = $request->getUploadedFiles();
        $body = $request->getParsedBody();

        $ctx = new FieldContext(
            '',
            $body,
            true,
            $request,
            $result,
            $files,
            $body,
        );

        $this->recordConstraintFailure($this->constraint, $ctx);

        return $result;
    }
}
