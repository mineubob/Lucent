<?php

declare(strict_types=1);

namespace Lucent\Validation;

use Lucent\Validation\Concerns\ResolvesPaths;

/**
 * Collects the outcome of validating a request.
 *
 * A Result holds any validation errors (keyed by dotted field path) and the
 * values produced by validation. Every present field is seeded with its raw
 * value; constraints that normalize (e.g. {@see \Lucent\Validation\Constraints\Numeric}
 * casting a numeric string) overwrite it with the transformed value. Values
 * are stored as nested arrays, so {@see value()} accepts a dotted path
 * (`user.name`) or a top-level key (`user`). It is returned by
 * {@see Validator::validate()} and passed to each {@see FieldContext}.
 *
 * A Result is **not coroutine-safe**: it is created fresh for every
 * {@see Validator::validate()} call and must never be shared across
 * concurrent coroutines (e.g. under Octane/Swoole). The combinators snapshot
 * and restore its error state during validation, so sharing one instance
 * across coroutines would corrupt each other's errors.
 */
final class Result
{
    use ResolvesPaths;

    /**
     * Validation errors keyed by dotted field path.
     *
     * @var array<string, list<string>>
     */
    private array $errors = [];

    /**
     * Validated values as a nested array.
     *
     * Seeded with each present field's raw value and overwritten by any
     * normalization that occurs during validation.
     *
     * @var array<string, mixed>
     */
    private array $values = [];

    /**
     * Record a validation error for a field.
     *
     * Multiple errors may be recorded for the same field.
     *
     * @param string $field The dotted path of the field that failed.
     * @param string $message The error message to record.
     * @return void
     */
    public function addError(string $field, string $message): void
    {
        $this->errors[$field] ??= [];
        $this->errors[$field][] = $message;
    }

    /**
     * Store a value at a dotted path.
     *
     * Called by the {@see Validator} to seed each present field's raw value,
     * and by {@see FieldContext::normalize()} to overwrite it with a
     * normalized value. Intermediate arrays are created as needed.
     *
     * @param string $path The dotted path of the field (e.g. `user.name`).
     * @param mixed $value The value to store.
     * @return void
     */
    public function set(string $path, mixed $value): void
    {
        $this->setSegments($this->segments($path), $value);
    }

    /**
     * Store a value at a pre-split dotted path.
     *
     * The hot path for storing values: {@see FieldContext} caches its path
     * segments and passes them here directly, avoiding a re-split on every
     * call. {@see set()} is a thin wrapper that splits the path first.
     *
     * Intermediate segments that do not yet exist are created as arrays. If an
     * intermediate segment already holds a non-array value, that value would
     * otherwise be silently destroyed; this throws instead so the conflict is
     * surfaced rather than corrupting the result.
     *
     * @param list<string> $segments The dotted path segments (e.g. `['user', 'name']`).
     * @param mixed $value The value to store.
     * @return void
     * @throws \LogicException If an intermediate segment holds a non-array value.
     */
    public function setSegments(array $segments, mixed $value): void
    {
        if ($segments === []) {
            $this->values = $value;
            return;
        }

        $ref = &$this->values;
        $last = array_pop($segments);

        // Descend through the intermediate segments, creating arrays as
        // needed. A non-array intermediate would otherwise be silently
        // destroyed, so throw instead of corrupting the result.
        foreach ($segments as $segment) {
            if (array_key_exists($segment, $ref) && !is_array($ref[$segment])) {
                throw new \LogicException(
                    "Cannot store value at '" . implode('.', [...$segments, $last]) . "': segment '$segment' already holds a non-array value.",
                );
            }
            if (!isset($ref[$segment])) {
                $ref[$segment] = [];
            }
            $ref = &$ref[$segment];
        }

        // The final segment is overwritten (e.g. seedRaw then normalize).
        $ref[$last] = $value;
        unset($ref);
    }

    /**
     * Ensure a container (array) exists at a dotted path.
     *
     * Creates an empty array at the path if none exists, creating any
     * intermediate arrays as needed. Used by container constraints
     * ({@see \Lucent\Validation\Combinators\Shape} and
     * {@see \Lucent\Validation\Combinators\Each}) to declare the field as a
     * structure whose children will be seeded individually, so undeclared
     * keys from the raw input are never stored.
     *
     * At the root (empty path) this is a no-op, since the values store is
     * always an array. An existing array at the path is left untouched; a
     * non-array value is replaced with an empty array.
     *
     * @param string $path The dotted path of the container field.
     * @return void
     */
    public function ensureContainer(string $path): void
    {
        $segments = $this->segments($path);

        $ref = &$this->values;
        foreach ($segments as $segment) {
            if (!isset($ref[$segment]) || !is_array($ref[$segment])) {
                $ref[$segment] = [];
            }
            $ref = &$ref[$segment];
        }
        unset($ref);
    }

    /**
     * Get all validation errors.
     *
     * @return array<string, list<string>> Errors keyed by dotted field path.
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Capture the current error state.
     *
     * Used by combinators such as {@see \Lucent\Validation\Combinators\Any}
     * to validate alternatives in isolation and roll back a failed branch's
     * errors via {@see restoreErrors()}.
     *
     * @return array<string, list<string>> A snapshot of the current errors.
     */
    public function snapshotErrors(): array
    {
        return $this->errors;
    }

    /**
     * Restore a previously captured error state.
     *
     * Replaces the current errors with the given snapshot, discarding any
     * errors recorded since it was captured.
     *
     * @param array<string, list<string>> $errors The error snapshot to restore.
     * @return void
     */
    public function restoreErrors(array $errors): void
    {
        $this->errors = $errors;
    }

    /**
     * Determine whether any validation errors were recorded.
     *
     * @return bool True if at least one error exists.
     */
    public function hasErrors(): bool
    {
        return count($this->errors) > 0;
    }

    /**
     * Get all validated values as a nested array.
     *
     * @return array<string, mixed> Values keyed by top-level field name.
     */
    public function values(): array
    {
        return $this->values;
    }

    /**
     * Determine whether a value exists at a dotted path.
     *
     * @param string $path The dotted path of the field.
     * @return bool True if a value is stored at the path.
     */
    public function hasValue(string $path): bool
    {
        return $path === '' || $this->tryValueAtPath($this->values, $path)[0];
    }

    /**
     * Get the value at a dotted path, or a default if absent.
     *
     * @param string $path The dotted path of the field.
     * @param mixed $default The value to return when the path has no stored value.
     * @return mixed The stored value, or $default.
     */
    public function value(string $path, mixed $default = null): mixed
    {
        if ($path === '') {
            return $this->values;
        }

        [$found, $value] = $this->tryValue($path);

        return $found ? $value : $default;
    }

    /**
     * Get the value at a dotted path in a single traversal.
     *
     * Distinguishes a key that is present with a `null` value from a key that
     * is absent entirely, without traversing the path twice. Delegates to the
     * shared {@see ResolvesPaths} helper.
     *
     * @param string $path The dotted path of the field.
     * @return array{0: bool, 1: mixed} `[true, value]` when the path resolves,
     *         `[false, null]` when it does not.
     */
    public function tryValue(string $path): array
    {
        return $this->tryValueAtPath($this->values, $path);
    }
}
