<?php

namespace Lucent\Http;

use Lucent\Facades\FileSystem;
use Lucent\Facades\Log;
use Lucent\Router;

class HttpRouter extends Router
{
    /**
     * Register a new route with optional controller
     */
    public function registerRoute(string $uri, string $type, string $method, ?string $controller = null, array $middleware = []): void
    {
        // Normalize the URI by trimming slashes and ensuring single slashes between segments
        $uri = trim($uri, '/');
        $prefix = trim($this->prefix ?? '', '/');

        // Build the full URI with prefix
        $fullUri = $prefix ? $prefix . '/' . $uri : $uri;

        // Store the route without leading slash for consistent comparison
        $this->routes[$type][$fullUri] = [
            "controller" => $controller,
            "method" => $method,
            "middleware" => array_merge($this->middleware, $middleware)
        ];
    }



    /**
     * Load routes from a file
     */
    public function loadRoutes(string $file, ?string $prefix = null): void
    {
        if ($prefix !== null) {
            Log::channel('phpunit')->info("Setting prefix before loading routes: " . $prefix);
            $previousPrefix = $this->prefix;
            $this->prefix = $prefix;
        }

        require_once FileSystem::rootPath().DIRECTORY_SEPARATOR.$file;

        if ($prefix !== null) {
            Log::channel('phpunit')->info("Restoring previous prefix: " . ($previousPrefix ?? 'none'));
            $this->prefix = $previousPrefix;
        }
    }
}