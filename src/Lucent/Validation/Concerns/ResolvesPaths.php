<?php

declare(strict_types=1);

namespace Lucent\Validation\Concerns;

/**
 * Shared helpers for resolving dotted field paths.
 *
 * A dotted path (`user.name`, `items.0`) addresses a value inside a nested
 * array. This trait provides the common operations — splitting a path into
 * segments and reading a value by path — used by {@see \Lucent\Validation\Result},
 * {@see \Lucent\Validation\FieldContext}, and the shape combinators.
 *
 * **Dotted keys are not supported.** The `.` character is always treated as a
 * path separator, so a field key that itself contains a dot (e.g. a literal
 * `user.name` key) is ambiguous and cannot be addressed. Field names should
 * avoid dots. The cached segments in {@see \Lucent\Validation\FieldContext}
 * are kept consistent with the dotted-path string: a leaf name containing a
 * dot is split into multiple segments, matching how the string is parsed.
 */
trait ResolvesPaths
{
    /**
     * Split a dotted path into its segments.
     *
     * The `.` character is always a separator; there is no escape mechanism
     * for a literal dot in a key.
     *
     * @param string $path The dotted path to split.
     * @return list<string> The path segments.
     */
    protected function segments(string $path): array
    {
        return $path === '' ? [] : explode('.', $path);
    }

    /**
     * Read a value from a nested array by dotted path in a single traversal.
     *
     * Distinguishes a key that is present with a `null` value from a key that
     * is absent entirely, without traversing the path twice.
     *
     * @param mixed $array The nested array to read from.
     * @param string $path The dotted path to read.
     * @return array{0: bool, 1: mixed} `[true, value]` when the path resolves,
     *         `[false, null]` when it does not.
     */
    protected function tryValueAtPath(mixed $array, string $path): array
    {
        $ref = $array;

        foreach ($this->segments($path) as $segment) {
            if (!is_array($ref) || !array_key_exists($segment, $ref)) {
                return [false, null];
            }
            $ref = $ref[$segment];
        }

        return [true, $ref];
    }
}