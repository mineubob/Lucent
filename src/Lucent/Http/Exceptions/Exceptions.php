<?php

namespace Lucent\Http\Exceptions;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionFunction;
use ReflectionNamedType;
use Throwable;

/**
 * Laravel-style exception manager.
 *
 * Holds two registries of callbacks:
 *  - report: closures that log exceptions (all matching callbacks run).
 *  - render: closures that build the HTTP response (first to return a
 *    Response wins; otherwise fall through to the framework default).
 *
 * Callbacks are dispatched by the type-hint of the closure's first parameter.
 */
final class Exceptions
{
    /** @var array<callable> */
    private array $reportCallbacks = [];

    /** @var array<callable> */
    private array $renderCallbacks = [];

    /**
     * Register a report callback.
     *
     * The callback is invoked for every exception whose type matches the
     * type-hint of its first parameter. All matching report callbacks run.
     *
     * @param callable(Throwable $e, ServerRequestInterface $request): void $callback
     * @return $this
     */
    public function report(callable $callback): self
    {
        $this->reportCallbacks[] = $callback;
        return $this;
    }

    /**
     * Register a render callback.
     *
     * The callback is invoked for every exception whose type matches the
     * type-hint of its first parameter. The first callback to return a
     * Response wins; a callback that returns null (or nothing) falls
     * through to the next callback, then to the framework default.
     *
     * @param callable(Throwable $e, ServerRequestInterface $request): ?ResponseInterface $callback
     * @return $this
     */
    public function render(callable $callback): self
    {
        $this->renderCallbacks[] = $callback;
        return $this;
    }

    /**
     * Run every report callback whose first-param type matches $e.
     *
     * $request is passed by value. Callbacks that need to stash state on
     * the request should use the shared mutable RequestContext bag, e.g.
     * RequestContext::fromRequest($request)?->set('key', $value), rather
     * than reassigning the request.
     */
    public function reportException(Throwable $e, ServerRequestInterface $request): void
    {
        foreach ($this->reportCallbacks as $callback) {
            if ($this->matches($callback, $e)) {
                $callback($e, $request);
            }
        }
    }

    /**
     * Return the first render callback's Response that matches $e,
     * or null to fall through to the framework default.
     */
    public function renderException(Throwable $e, ServerRequestInterface $request): ?ResponseInterface
    {
        foreach ($this->renderCallbacks as $callback) {
            if ($this->matches($callback, $e)) {
                $response = $callback($e, $request);
                if ($response instanceof ResponseInterface) {
                    return $response;
                }
            }
        }
        return null;
    }

    /**
     * Match a callback to an exception by the type-hint of its first
     * parameter (mirrors Laravel). A callback typed to Throwable matches
     * everything; one typed to a specific class matches only that class
     * (and subclasses).
     */
    private function matches(callable $callback, Throwable $e): bool
    {
        $ref = new ReflectionFunction(\Closure::fromCallable($callback));
        $params = $ref->getParameters();
        if ($params === []) {
            return false;
        }
        $type = $params[0]->getType();
        if ($type === null || !($type instanceof ReflectionNamedType)) {
            return false;
        }
        return is_a($e, $type->getName());
    }
}