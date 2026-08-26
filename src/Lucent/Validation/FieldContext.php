<?php

declare(strict_types=1);

namespace Lucent\Validation;

use Lucent\Validation\Concerns\ResolvesPaths;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Provides the runtime context for a single field being validated.
 *
 * A FieldContext is created by the {@see Validator} for each constraint and
 * exposes the field's dotted path, its raw value, and the shared
 * {@see Result}. It also offers helpers for reading uploaded files, reading
 * other fields' values, and normalizing the current value.
 *
 * Nested constraints ({@see \Lucent\Validation\Combinators\Shape} and
 * {@see \Lucent\Validation\Combinators\Each}) derive child contexts via
 * {@see child()}, which extends the dotted path so errors and normalized
 * values are namespaced (e.g. `user.name`).
 *
 * The context is decoupled from HTTP: it validates plain data and never
 * exposes a request object. Treat all field values as **untrusted** — never
 * interpolate them into SQL, commands, or file paths without validation.
 *
 * Per-request values (e.g. the originating ServerRequest, the authenticated
 * user, a tenant id) can be passed in via the context bag — see {@see context()}.
 * The bag is resolved once at construction and is immutable for the lifetime
 * of the context, which is created fresh for every validation call, so it is
 * coroutine-safe and never bleeds across requests.
 */
final class FieldContext
{
    use ResolvesPaths;

    /**
     * Arbitrary per-validation values keyed by name.
     *
     * Resolved once at construction and read via {@see context()}. Stored on the
     * context — created fresh per validation call — so it is coroutine-safe
     * and never shared across requests.
     *
     * @var array<string, mixed>
     */
    private readonly array $context;

    /**
     * Create a new field context.
     *
     * @param string $field The dotted path of the field being validated (e.g. `user.name`).
     * @param mixed $value The raw value of the field.
     * @param bool $present Whether the field key was present in the data.
     * @param Result $result The result object that collects errors and normalized values.
     * @param array<string, UploadedFileInterface>|null $files The uploaded files, or null if none.
     * @param mixed $body The data payload being validated, or null if none.
     * @param array<string, mixed> $context Per-validation values (e.g. the originating
     *        ServerRequest, the authenticated user) exposed to constraints via {@see get()}.
     * @param string $name The leaf field name, used for file lookups. Defaults to $field.
     */
    /**
     * Whether a child constraint failed during validation of this field.
     *
     * Used by the shape combinators ({@see \Lucent\Validation\Combinators\Shape}
     * and {@see \Lucent\Validation\Combinators\Each}) to suppress their generic
     * error message when a child already recorded a specific error. Stored on
     * the context — which is created fresh for every validation call — rather
     * than on the constraint instance, so it cannot bleed across requests
     * under long-running runtimes (Octane/Swoole/RoadRunner).
     */
    public bool $childFailed = false;

    /**
     * The constraint that failed, when this field's validation is a composite
     * ({@see \Lucent\Validation\Combinators\All}).
     *
     * Used to delegate the error message to the failing constraint. Stored on
     * the context for the same reason as {@see $childFailed}: it is
     * per-validation and never shared across requests.
     */
    public ?Constraint $failedConstraint = null;

    /**
     * The dotted path split into segments, cached to avoid re-splitting on
     * every {@see Result} write.
     *
     * @var list<string>
     */
    public array $segments;

    public function __construct(
        public readonly string $field,
        public private(set) mixed $value,
        public readonly bool $present,
        public readonly Result $result,
        private readonly array|null $files,
        private readonly mixed $body,
        array $context = [],
        public string $name = '',
    ) {
        $this->context = $context;

        if ($this->name === '') {
            $this->name = $this->field;
        }

        $this->segments = $this->segments($this->field);
    }

    /**
     * Derive a child context for a sub-field.
     *
     * Extends the current dotted path with the sub-field name so errors and
     * normalized values are namespaced. Used by {@see \Lucent\Validation\Combinators\Shape}
     * and {@see \Lucent\Validation\Combinators\Each}.
     *
     * @param int|string $name The sub-field name (leaf). Integer keys (e.g.
     *        tuple indices) are cast to a string for the dotted path.
     * @param mixed $value The sub-field's raw value.
     * @param bool $present Whether the sub-field key was present.
     * @return self A new context for the sub-field.
     */
    public function child(int|string $name, mixed $value, bool $present): self
    {
        $name = (string) $name;
        $path = $this->field === '' ? $name : $this->field . '.' . $name;

        $child = new self(
            $path,
            $value,
            $present,
            $this->result,
            $this->files,
            $this->body,
            $this->context,
            $name,
        );

        // Reuse the parent's cached segments to avoid re-splitting the shared
        // prefix of the dotted path. The leaf name is split through the same
        // segments() helper so the cached segments always agree with the
        // dotted-path string. (Dotted keys are not supported — see
        // ResolvesPaths — so a leaf name is normally a single segment.)
        $child->segments = [...$this->segments, ...$this->segments($name)];

        return $child;
    }

    /**
     * Get a value from the context bag, cast to a given type.
     *
     * A typed getter mirroring {@see Result::valueAs()}: scalar types are
     * passed as string literals (`'int'`, `'string'`, `'bool'`, `'float'`,
     * `'array'`), userland classes via `::class`. Falls back to the default
     * when the key is absent or the value cannot be cast.
     *
     * ```php
     * $request = $ctx->context('request', ServerRequestInterface::class);
     * $userId  = $ctx->context('user_id', 'int');
     * ```
     *
     * @template T
     * @param string $key The name of the value.
     * @param class-string<T>|string $type The type to cast to (e.g. `'int'` or `User::class`).
     * @param T|null $default The value to return when the key is absent or the
     *        value cannot be cast to the requested type.
     * @return ($default is null ? T|null : T) The value cast to the requested
     *         type, or $default. When $default is non-null the return is always T.
     */
    public function context(string $key, string $type, mixed $default = null): mixed
    {
        $value = $this->context[$key] ?? $default;

        if ($value === $default) {
            return $default;
        }

        return match ($type) {
            'int'    => (int) $value,
            'string' => (string) $value,
            'bool'   => (bool) $value,
            'float'  => (float) $value,
            'array'  => is_array($value) ? $value : $default,
            default  => $value instanceof $type ? $value : $default,
        };
    }

    /**
     * Get the uploaded file for the current field, if any.
     *
     * @return UploadedFileInterface|null The uploaded file, or null if the
     *         field has no file or the file is not a valid upload.
     */
    public function file(): ?UploadedFileInterface
    {
        if ($this->files === null) {
            return null;
        }

        $file = array_key_exists($this->name, $this->files) ? $this->files[$this->name] : null;
        return $file instanceof UploadedFileInterface ? $file : null;
    }

    /**
     * Store the field's raw value in the result.
     *
     * Called by container constraints for each present child before running
     * its constraint, so the raw value is retrievable even when the child
     * constraint only validates and does not normalize. A child constraint
     * that does normalize overwrites this via {@see normalize()}.
     *
     * Only scalar values are seeded. Arrays and objects are containers: a
     * nested {@see \Lucent\Validation\Combinators\Shape} or
     * {@see \Lucent\Validation\Combinators\Each} declares them via
     * {@see Result::ensureContainer()} and seeds their declared sub-fields, so
     * seeding the whole raw value here would leak undeclared keys.
     *
     * @return void
     */
    public function seedRaw(): void
    {
        if (is_array($this->value) || is_object($this->value)) {
            return;
        }

        $this->result->setSegments($this->segments, $this->value);
    }

    /**
     * Get the value of another field.
     *
     * Returns the current field's value when the requested field is this one.
     * Otherwise, resolves the requested field relative to the current field's
     * parent (so a sibling lookup inside a nested shape works), prefers a
     * normalized value already stored in the result, and falls back to the
     * raw request body.
     *
     * @param string $field The name of the field to read.
     * @return mixed The value of the requested field, or null if absent.
     */
    public function valueOf(string $field): mixed
    {
        if ($field === $this->field) {
            return $this->value;
        }

        $target = $this->resolveSibling($field);

        [$found, $value] = $this->result->tryValue($target);
        if ($found) {
            return $value;
        }

        [$found, $value] = $this->tryValueAtPath($this->body, $target);

        return $found ? $value : null;
    }

    /**
     * Normalize the current field's value.
     *
     * Updates the context's value and stores the normalized value in the
     * result at the field's dotted path so it can be retrieved later via
     * {@see Result::value()}.
     *
     * @param mixed $value The normalized value to store.
     * @return void
     */
    public function normalize(mixed $value): void
    {
        $this->value = $value;
        $this->result->setSegments($this->segments, $value);
    }

    /**
     * Resolve a sibling field name against the current field's parent path.
     *
     * The resolution algorithm is:
     *
     * 1. A field name containing a dot is treated as an **absolute** dotted
     *    path and returned unchanged (e.g. `user.password_confirmation` stays
     *    `user.password_confirmation`). This lets a nested shape reference a
     *    field anywhere in the tree, not just a sibling.
     * 2. A bare leaf name is resolved relative to the current field's parent
     *    (e.g. inside `user.password`, `password_confirmation` resolves to
     *    `user.password_confirmation`).
     * 3. At the top level (no dot in the current field), a bare leaf name is
     *    returned unchanged.
     *
     * @param string $field The leaf name or absolute dotted path of the field.
     * @return string The resolved dotted path.
     */
    private function resolveSibling(string $field): string
    {
        if (str_contains($field, '.')) {
            return $field;
        }

        $dot = strrpos($this->field, '.');

        if ($dot === false) {
            return $field;
        }

        return substr($this->field, 0, $dot) . '.' . $field;
    }
}
