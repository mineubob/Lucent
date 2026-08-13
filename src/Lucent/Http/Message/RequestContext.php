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
        return $this->data[$key] ?? $default;
    }

    /**
     * Read a value from the context, guaranteed to be an instance of a class.
     *
     * Returns the stored value when it is an instance of the given class,
     * otherwise returns the default. This is the type-safe counterpart to
     * {@see get()}: it lets middleware and rules stash objects (a User, a
     * Session, a request id) and read them back without an instanceof check
     * at every call site.
     *
     * @template T
     * @param string $key The context key
     * @param class-string<T> $class The expected class of the stored value
     * @param T|null $default Default value if the key is not set or holds a
     *                        value that is not an instance of $class
     * @return T|null
     */
    public function getTyped(string $key, string $class, mixed $default = null): mixed
    {
        $value = $this->data[$key] ?? null;

        return $value instanceof $class ? $value : $default;
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