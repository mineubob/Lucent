<?php

namespace Lucent;

use Lucent\Facades\Log;
use Lucent\Http\Exceptions\HttpException;
use Lucent\Http\HttpStatus;

abstract class Router
{
    // HTTP Method Constants
    public static string $ROUTE_POST = "POST";
    public static string $ROUTE_GET = "GET";
    public static string $ROUTE_PATCH = "PATCH";
    public static string $ROUTE_DELETE = "DELETE";
    public static string $ROUTE_CLI = "CLI";

    // Route storage
    protected array $routes = [];
    protected array $groupStack = [];

    // Current route group attributes
    protected array $middleware = [];
    protected ?string $prefix = null;
    protected ?string $namespace = null;
    protected ?string $defaultController = null;
    protected array $disabled = ["*"=>false];

    /**
     * Register a new route with the router
     */
    abstract public function registerRoute(string $uri, string $type, string $method, ?string $controller = null, array $middleware = []);

    /**
     * Load routes from a file
     */
    abstract public function loadRoutes(string $file, ?string $prefix = null);

    /**
     * Set the default controller for the current group
     */
    public function setDefaultController(?string $controller): void
    {
        $this->defaultController = $controller;
    }

    /**
     * Get the appropriate controller for a route
     */
    protected function resolveController(?string $routeController): ?string
    {
        return $routeController ?? $this->defaultController;
    }

    /**
     * Start a new route group with shared attributes
     */
    public function group(array $attributes, callable $callback): void
    {
        $this->groupStack[] = [
            'middleware' => $this->middleware,
            'prefix' => $this->prefix,
            'namespace' => $this->namespace,
            'defaultController' => $this->defaultController
        ];

        // Merge new group attributes
        $this->middleware = array_merge($this->middleware, $attributes['middleware'] ?? []);
        // Append the new prefix onto the existing one, preserving the
        // accumulation behaviour for nested groups (e.g. /api + /v1 = /api/v1).
        // When no prefix is provided, keep the current value (which may be
        // null) rather than converting it to an empty string.
        if (array_key_exists('prefix', $attributes)) {
            $this->prefix = ($this->prefix ?? '') . $attributes['prefix'];
        }
        // else: $this->prefix unchanged (preserves null distinct from '')
        $this->namespace = $attributes['namespace'] ?? $this->namespace;
        $this->defaultController = $attributes['defaultController'] ?? $this->defaultController;

        // Execute the group's route definitions
        $callback($this);

        // Restore previous group attributes
        $previous = array_pop($this->groupStack);
        $this->middleware = $previous['middleware'];
        $this->prefix = $previous['prefix'];
        $this->namespace = $previous['namespace'];
        $this->defaultController = $previous['defaultController'];
    }

    /**
     * Check if a route segment is a parameter and extract its name
     * Returns [bool $isParameter, string $paramName]
     */
    protected function parseRouteParameter(string $segment): array
    {
        if (preg_match('/^\{([a-zA-Z_][a-zA-Z0-9_]*)\}$/', $segment, $matches)) {
            return [true, $matches[1]];
        }
        return [false, ''];
    }

    /**
     * Allows for all routes '*' or specific routes to be disabled.
     * Returns void
     */
    public function disable(string|array $route): void
    {
        if (is_array($route)) {
            foreach ($route as $r) {
                $this->disabled[$r] = true;
            }
        } else {
            $this->disabled[$route] = true;
        }
    }

    /**
     * Checks if a specific route is disabled, returns true or false.
     */
    protected function isDisabled(string $uri): bool
    {
        return ($this->disabled["*"] ?? false) || ($this->disabled[$uri] ?? false);
    }

    /**
     * Convert a URI to an array of segments, removing empty segments
     */
    /**
     * Modify getUriAsArray for more flexible CLI argument parsing
     */
    public function getUriAsArray(?string $url = null, string $separator = "/"): array
    {
        // If no URL provided, check for CLI arguments
        if ($url === null) {
            // Check if running in CLI mode
            if (php_sapi_name() === 'cli') {
                // Use global $_SERVER['argv'] for CLI arguments
                $url = implode(' ', array_slice($_SERVER['argv'], 1));
            } else {
                $url = $_SERVER["REQUEST_URI"] ?? '';
            }
        }

        // Remove any protocol, domain, or query string
        $url = parse_url($url, PHP_URL_PATH);
        if ($url === false || $url === null) {
            $url = '';
        }

        // Decode URL to handle special characters
        $url = urldecode($url);

        // Remove query string if present (must use !== false - strpos returns 0
        // when '?' is the first character, which is falsy)
        $pos = strpos($url, "?");
        if ($pos !== false) {
            $url = substr($url, 0, $pos);
        }

        // Normalize URL for CLI
        $url = preg_replace('/\s+/', $separator, trim($url));

        // Normalize slashes and trim
        $url = trim($url, '/');

        // Split and filter empty segments
        return array_values(array_filter(explode($separator, $url), function($segment) {
            return $segment !== '';
        }));
    }
    /**
     * Find and analyze a matching route for the current request
     */
    public function analyseRouteAndLookup(array $route): array
    {
        $uri = $route;
        $requestMethod = $_SERVER["REQUEST_METHOD"] ?? 'GET';


        if (!isset($this->routes[$requestMethod])) {
            throw new HttpException(HttpStatus::NOT_FOUND);
        }

        foreach ($this->routes[$requestMethod] as $key => $route) {
            if ($match = $this->matchRoute($key, $uri, '/', $route)) {
                if ($this->isDisabled($key)) {
                    throw new HttpException(HttpStatus::FORBIDDEN);
                }
                return $match;
            }
        }


        throw new HttpException(HttpStatus::NOT_FOUND);
    }

    /**
     * Check if a route matches the current URI and extract parameters
     */
    protected function matchRoute(string $routePath, array $uri, string $separator, array $route): ?array
    {
        // Normalize route path
        $routePath = trim($routePath, '/');
        $routeSegments = $this->getUriAsArray($routePath, $separator);

        if (count($routeSegments) !== count($uri)) {
            return null;
        }

        $variables = [];
        $matches = true;

        for ($i = 0; $i < count($routeSegments); $i++) {
            [$isParameter, $paramName] = $this->parseRouteParameter($routeSegments[$i]);

            if (!$isParameter) {
                if ($routeSegments[$i] !== $uri[$i]) {
                    $matches = false;
                    break;
                }
            } else {
                $variables[$paramName] = $uri[$i];
            }
        }

        if (!$matches) {
            return null;
        }

        Log::channel('lucent.routing')->info("[Router] Found route : " . $routePath."\n    Http Method:".$_SERVER['REQUEST_METHOD']."\n    Http Controller: {$route["controller"]}@{$route["method"]}");

        return [
            "route" => $routePath,
            "outcome" => true,
            "controller" => $route["controller"],
            "method" => $route["method"],
            "variables" => $variables,
            "middleware" => $route["middleware"] ?? []
        ];
    }

    /**
     * Set active middleware for the current route group
     */
    public function setActiveMiddleware(array $middleware): void
    {
        $this->middleware = $middleware;
    }

    /**
     * Set the current route group prefix
     */
    public function setPrefix(?string $prefix): void
    {
        $this->prefix = $prefix;
    }

    /**
     * Get the current route group prefix
     */
    public function getPrefix(): ?string
    {
        return $this->prefix;
    }

    /**
     * Get the current route group middleware
     */
    public function getMiddleware(): array
    {
        return $this->middleware;
    }

    /**
     * Get the full path for a route, including prefix
     */
    protected function getFullPath(string $path): string
    {
        return $this->prefix ? rtrim($this->prefix, '/') . '/' . ltrim($path, '/') : $path;
    }

    /**
     * Get the full class name for a controller, including namespace
     */
    protected function getFullClassName(string $controller): string
    {
        return $this->namespace ? rtrim($this->namespace, '\\') . '\\' . $controller : $controller;
    }

    public function reset(): void
    {
        $this->routes = [];
        $this->groupStack = [];
        $this->middleware = [];
        $this->prefix = null;
        $this->namespace = null;
        $this->defaultController = null;
        $this->disabled = ["*" => false];
    }

    public function getRoutes(bool $activeOnly = true): array
    {
        if ($activeOnly) {
            $active = [];
            foreach ($this->routes as $method => $routes) {
                foreach ($routes as $uri => $route) {
                    if (!$this->isDisabled($uri)) {
                        $active[$method][$uri] = $route;
                    }
                }
            }
            return $active;
        }

        return $this->routes;
    }
}