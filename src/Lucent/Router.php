<?php

namespace Lucent;

use Lucent\Facades\Log;

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
        $this->prefix = $this->prefix . ($attributes['prefix'] ?? '');
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

        // Decode URL to handle special characters
        $url = urldecode($url);

        Log::channel('phpunit')->info("Processing raw URL: " . $url);

        // Remove query string if present
        if ($pos = strpos($url, "?")) {
            $url = substr($url, 0, $pos);
        }

        // Normalize URL for CLI
        $url = preg_replace('/\s+/', $separator, trim($url));

        // Normalize slashes and trim
        $url = trim($url, '/');

        Log::channel('phpunit')->info("Normalized CLI URL: " . $url);

        // Split and filter empty segments
        $segments = array_values(array_filter(explode($separator, $url), function($segment) {
            return $segment !== '';
        }));

        Log::channel('phpunit')->info("CLI URL segments: " . implode(', ', $segments));

        return $segments;
    }
    /**
     * Find and analyze a matching route for the current request
     */
    public function analyseRouteAndLookup(array $route): array
    {
        $uri = $route;
        $requestMethod = $_SERVER["REQUEST_METHOD"] ?? 'GET';

        Log::channel('phpunit')->info("Analyzing route for method: " . $requestMethod);
        Log::channel('phpunit')->info("Looking for URI path: " . implode('/', $uri));

        if (!isset($this->routes[$requestMethod])) {
            Log::channel('phpunit')->warning("No routes registered for method: " . $requestMethod);
            return [
                "route" => null,
                "outcome" => false
            ];
        }

        Log::channel('phpunit')->info("Available routes for " . $requestMethod . ":");
        foreach ($this->routes[$requestMethod] as $path => $details) {
            Log::channel('phpunit')->info("  - " . $path . " => " . $details["controller"] . "@" . $details["method"]);
        }

        foreach ($this->routes[$requestMethod] as $key => $route) {
            Log::channel('phpunit')->info("Checking route: " . $key);

            if ($match = $this->matchRoute($key, $uri, '/', $route)) {
                Log::channel('phpunit')->info("Route matched: " . $key);
                return $match;
            }
        }

        Log::channel('phpunit')->warning("No matching route found");
        return [
            "route" => null,
            "outcome" => false
        ];
    }

    /**
     * Check if a route matches the current URI and extract parameters
     */
    protected function matchRoute(string $routePath, array $uri, string $separator, array $route): ?array
    {
        // Normalize route path
        $routePath = trim($routePath, '/');
        $routeSegments = $this->getUriAsArray($routePath, $separator);

        // Get URI as string for comparison
        $uriString = implode('/', $uri);

        Log::channel('phpunit')->info("Comparing route:");
        Log::channel('phpunit')->info("  Route pattern: " . $routePath);
        Log::channel('phpunit')->info("  URI to match: " . $uriString);
        Log::channel('phpunit')->info("  Route segments: " . implode('/', $routeSegments));
        Log::channel('phpunit')->info("  URI segments: " . implode('/', $uri));

        if (count($routeSegments) !== count($uri)) {
            Log::channel('phpunit')->info("  Segment count mismatch - Route: " . count($routeSegments) . ", URI: " . count($uri));
            return null;
        }

        $variables = [];
        $matches = true;

        for ($i = 0; $i < count($routeSegments); $i++) {
            [$isParameter, $paramName] = $this->parseRouteParameter($routeSegments[$i]);

            if (!$isParameter) {
                if ($routeSegments[$i] !== $uri[$i]) {
                    Log::channel('phpunit')->info("  Segment mismatch at position {$i}: Expected '{$routeSegments[$i]}', got '{$uri[$i]}'");
                    $matches = false;
                    break;
                }
            } else {
                $variables[$paramName] = $uri[$i];
                Log::channel('phpunit')->info("  Parameter extracted: {$paramName} = {$uri[$i]}");
            }
        }

        if (!$matches) {
            return null;
        }

        Log::channel('phpunit')->info("Route matched successfully with variables: " . json_encode($variables));

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
    }
}