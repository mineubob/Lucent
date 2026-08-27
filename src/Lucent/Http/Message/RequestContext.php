<?php

namespace Lucent\Http\Message;

use Psr\Http\Message\ServerRequestInterface;

/**
 * A mutable, request-scoped context bag.
 *
 * Holds user-written state that rules, middleware, and controllers share
 * during a single request. Unlike the PSR-7 request (which is immutable),
 * this object is mutated in place, so a write made anywhere is visible
 * everywhere that holds the same instance.
 *
 * One instance is created per request and attached to the request as the
 * 'context' attribute (see {@see ServerRequest::capture()} and
 * {@see ServerRequest::create()}). Because the request is passed by value
 * but the bag is a shared object, every copy of the request points at the
 * same bag — no by-reference threading is needed.
 */
class RequestContext
{
    /** @var array<string, mixed> */
    private array $data = [];

    /**
     * Retrieve the context bag attached to a request, if any.
     *
     * Works with any {@see ServerRequestInterface}: Lucent's ServerRequest
     * carries a RequestContext as its 'context' attribute, and other
     * implementations may do the same. Returns null when the request has no
     * context bag attached.
     *
     * @param ServerRequestInterface $request The request to inspect
     * @return self|null The attached context bag, or null if none
     */
    public static function fromRequest(ServerRequestInterface $request): ?self
    {
        $context = $request->getAttribute('context');

        return $context instanceof self ? $context : null;
    }

    /**
     * Store a value in the context.
     *
     * @param string $key The context key
     * @param mixed $value The value to store
     * @return void
     */
    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    /**
     * Read a value from the context.
     *
     * @param string $key The context key
     * @param mixed $default Default value if the key is not set
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->data) ? $this->data[$key] : $default;
    }

    /**
     * Read a value from the context, guaranteed to match a given type.
     *
     * Returns the stored value when it matches the type, otherwise returns
     * the default. This is the type-safe counterpart to {@see get()}: it lets
     * middleware and rules stash objects (a User, a Session, a request id) or
     * scalars and read them back without a manual instanceof / is_* check at
     * every call site.
     *
     * The type may be a class or interface name (checked with instanceof) or
     * a builtin type name: string, int, float, bool, array, object, callable,
     * iterable, numeric, scalar, resource, or null.
     *
     * @template T
     * @param string $key The context key
     * @param class-string<T>|string $type The expected type of the stored
     *                                     value (class name or builtin type)
     * @param T|null $default Default value if the key is not set or holds a
     *                        value that does not match $type
     * @return ($default is null ? T|null : T) The stored value when it matches
     *         $type, otherwise $default. When $default is non-null the return is always T.
     */
    public function getTyped(string $key, string $type, mixed $default = null): mixed
    {
        $value = $this->data[$key] ?? null;

        return $this->matchesType($value, $type) ? $value : $default;
    }

    /**
     * Read a value from the context, guaranteed to match a given type, or
     * throw if it is missing or of the wrong type.
     *
     * This is the fail-fast counterpart to {@see getTyped()}: it is intended
     * for values the code genuinely cannot proceed without (an authenticated
     * user, a session, a request id). Instead of returning a default and
     * forcing the caller to null-check, it throws a descriptive
     * {@see \RuntimeException} so the failure surfaces at the point of use
     * with a clear message rather than as a confusing "call to member
     * function on null" deeper in the stack.
     *
     * The type may be a class or interface name (checked with instanceof) or
     * a builtin type name: string, int, float, bool, array, object, callable,
     * iterable, numeric, scalar, resource, or null.
     *
     * @template T
     * @param string $key The context key
     * @param class-string<T>|string $type The expected type of the stored
     *                                     value (class name or builtin type)
     * @return T The stored value, guaranteed to match $type
     * @throws \RuntimeException When the key is not set or holds a value that
     *         does not match $type
     */
    public function requireTyped(string $key, string $type): mixed
    {
        $value = $this->getTyped($key, $type);

        if ($value === null && !$this->matchesType(null, $type)) {
            throw new \RuntimeException(
                sprintf(
                    'Context key "%s" is missing or does not hold a value of type "%s".',
                    $key,
                    $type
                )
            );
        }

        return $value;
    }

    /**
     * Determine whether a value matches a class name or builtin type.
     *
     * @param mixed $value The value to check
     * @param string $type A class/interface name or a builtin type name
     * @return bool
     */
    private function matchesType(mixed $value, string $type): bool
    {
        if (class_exists($type) || interface_exists($type)) {
            return $value instanceof $type;
        }

        return match ($type) {
            'string' => is_string($value),
            'int', 'integer' => is_int($value),
            'float', 'double' => is_float($value),
            'bool', 'boolean' => is_bool($value),
            'array' => is_array($value),
            'object' => is_object($value),
            'callable' => is_callable($value),
            'iterable' => is_iterable($value),
            'numeric' => is_numeric($value),
            'scalar' => is_scalar($value),
            'resource' => is_resource($value),
            'null' => $value === null,
            default => false,
        };
    }

    /**
     * Determine whether a key is set in the context.
     *
     * @param string $key The context key
     * @return bool
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    /**
     * Get all context values.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->data;
    }
}