<?php
declare(strict_types=1);


namespace Lucent\Routing\Rest;

use Lucent\Routing\RouteGroup;
use RuntimeException;

/**
 * A route group tailored for RESTful resource definitions.
 *
 * Provides fluent verb helpers (get/post/put/patch/delete) that all funnel
 * through a single generic {@see method()} registration, so the default
 * controller fallback and HTTP-verb normalisation live in one place.
 */
class RestRouteGroup extends RouteGroup
{

    /**
     * Fully-qualified controller class used when a verb helper is called
     * without an explicit $controller. Null until {@see defaultController()}
     * is called.
     *
     * @var class-string|null
     */
    protected ?string $defaultControllerClass = null;

    /** Optional URI prefix applied to every route registered in this group. */
    protected ?string $prefix = null;

    /**
     * Register a GET route.
     *
     * @param string            $path       The URI path (relative to any group prefix).
     * @param string            $method     The controller method to invoke.
     * @param class-string|null $controller The controller class; falls back to the group default.
     */
    public function get(string $path, string $method, ?string $controller = null): static
    {
        return $this->method('GET', $path, $method, $controller);
    }

    /**
     * Register a POST route.
     *
     * @param string            $path       The URI path (relative to any group prefix).
     * @param string            $method     The controller method to invoke.
     * @param class-string|null $controller The controller class; falls back to the group default.
     */
    public function post(string $path, string $method, ?string $controller = null): static
    {
        return $this->method('POST', $path, $method, $controller);
    }

    /**
     * Register a PUT route.
     *
     * @param string            $path       The URI path (relative to any group prefix).
     * @param string            $method     The controller method to invoke.
     * @param class-string|null $controller The controller class; falls back to the group default.
     */
    public function put(string $path, string $method, ?string $controller = null): static
    {
        return $this->method('PUT', $path, $method, $controller);
    }

    /**
     * Register a PATCH route.
     *
     * @param string            $path       The URI path (relative to any group prefix).
     * @param string            $method     The controller method to invoke.
     * @param class-string|null $controller The controller class; falls back to the group default.
     */
    public function patch(string $path, string $method, ?string $controller = null): static
    {
        return $this->method('PATCH', $path, $method, $controller);
    }

    /**
     * Register a DELETE route.
     *
     * @param string            $path       The URI path (relative to any group prefix).
     * @param string            $method     The controller method to invoke.
     * @param class-string|null $controller The controller class; falls back to the group default.
     */
    public function delete(string $path, string $method, ?string $controller = null): static
    {
        return $this->method('DELETE', $path, $method, $controller);
    }

    /**
     * Register a route for an arbitrary HTTP method (e.g. 'HEAD', 'OPTIONS',
     * or any custom verb the router should accept).
     *
     * This is the single registration point that all verb helpers delegate to.
     *
     * @param string            $httpMethod The HTTP verb (case-insensitive; uppercased here).
     * @param string            $path       The URI path (relative to any group prefix).
     * @param string            $method     The controller method to invoke.
     * @param class-string|null $controller The controller class; falls back to the group default.
     */
    public function method(string $httpMethod, string $path, string $method, ?string $controller = null): static
    {
        if ($controller === null) {
            $controller = $this->defaultControllerClass;
        }
        if ($controller === null) {
            throw new RuntimeException(
                "No controller resolved for route [{$httpMethod} {$path}]. " .
                'Pass a $controller or call defaultController() on the group first.'
            );
        }
        return $this->registerRoute($path, strtoupper($httpMethod), [$controller, $method]);
    }

    /**
     * Set the default controller class used by verb helpers when none is given.
     *
     * @param class-string $class The fully-qualified controller class name.
     */
    public function defaultController(string $class): static
    {
        $this->defaultControllerClass = $class;
        return $this;
    }

    /**
     * Append a URI prefix to this group.
     *
     * Appends to any existing prefix (which may have been inherited from an
     * outer Router::group()) so nested group prefixes accumulate.
     *
     * @param string $prefix The prefix segment(s) to append.
     */
    public function prefix(string $prefix): static
    {
        // Append to any existing prefix (which may have been inherited from
        // an outer Router::group()) so nested group prefixes accumulate.
        $this->prefix = ($this->prefix ?? '') . '/' . ltrim($prefix, '/');
        return $this;
    }

    /**
     * Set the base prefix directly (replacement, not appending).
     *
     * Used by RestRouteBuilder to inherit the outer Router group prefix.
     *
     * @param string $prefix The prefix to set, replacing any existing value.
     */
    public function setBasePrefix(string $prefix): static
    {
        $this->prefix = $prefix;
        return $this;
    }

    /**
     * Build the full URI path for a route by combining the group prefix with
     * the given path, normalising slashes.
     *
     * @param string $path The route path relative to the group prefix.
     */
    protected function buildPath(string $path): string
    {
        $prefix = $this->prefix ? rtrim($this->prefix, '/') : '';
        return $prefix ? $prefix . '/' . ltrim($path, '/') : $path;
    }
}
