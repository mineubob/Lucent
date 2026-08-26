<?php

declare(strict_types=1);

namespace Lucent\Validation;

use Lucent\Validation\Combinators\Shape;
use Lucent\Validation\Concerns\RecordsConstraintFailure;

/**
 * Applies a set of constraints to a raw data payload.
 *
 * The Validator is constructed with either a single top-level constraint
 * (typically a {@see Shape} for an object body or an {@see \Lucent\Validation\Combinators\Each}
 * for an array body) or a flat map of constraints keyed by field name, which
 * is sugar for a top-level {@see Shape}. Calling {@see validate()} runs the
 * constraint(s) against the data and returns a {@see Result} containing any
 * errors and validated values.
 *
 * The Validator is decoupled from HTTP: it validates plain arrays, objects,
 * or null (and an optional map of uploaded files). HTTP callers use the
 * convenience wrapper on {@see \Lucent\Http\Message\ServerRequest::validate()},
 * which passes the parsed body and uploaded files through unchanged.
 */
final class Validator
{
    use RecordsConstraintFailure;

    /**
     * The top-level constraint applied to the data payload.
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
     * Validate a data payload against the configured constraints.
     *
     * The top-level constraint is applied to the data. Failed constraints
     * record an error message on the result; successful constraints may
     * normalize the value, which is stored on the result.
     *
     * @param array|object|null $body The data to validate (e.g. a parsed request body).
     * @param array<string, \Psr\Http\Message\UploadedFileInterface> $files Optional
     *        uploaded files keyed by field name, for file constraints.
     * @return Result The validation result containing errors and validated values.
     */
    public function validate(array|object|null $body, array $files = []): Result
    {
        $result = new Result();

        $ctx = new FieldContext(
            '',
            $body,
            true,
            $result,
            $files,
            $body,
        );

        $this->recordConstraintFailure($this->constraint, $ctx);

        return $result;
    }
}
