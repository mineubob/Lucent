<?php
declare(strict_types=1);


namespace Lucent\Http;

use Lucent\Facades\FileSystem;
use Lucent\Filesystem\Exceptions\FileNotFound;
use Lucent\Filesystem\File;
use Lucent\Http\Exceptions\HttpException;
use Lucent\Router;
use RuntimeException;

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

        // Apply the namespace group attribute (if any) to the controller.
        if ($controller !== null) {
            $controller = $this->getFullClassName($controller);
        }

        // Store the route without leading slash for consistent comparison
        $this->routes[$type][$fullUri] = [
            "controller" => $controller,
            "method" => $method,
            "middleware" => array_merge($this->middleware, $middleware)
        ];
    }


    /**
     * Load routes from a file
     * @throws FileNotFound
     */
    public function loadRoutes(string $file, ?string $prefix = null): void
    {
        // Detect absolute paths (e.g. from glob() in Application::boot()) so
        // the File constructor doesn't prepend rootPath() a second time.
        $file = new File($file, null, FileSystem::isAbsolute($file));

        if (!$file->exists()) {
            throw new HttpException(
                HttpStatus::SERVER_ERROR,
                "Route file not found",
                new RuntimeException("File not found: $file->path")
            );
        }

        //Setting prefix before loading routes
        if ($prefix !== null) {
            $previousPrefix = $this->prefix;
            $this->prefix = $prefix;
        }

        try {
            require_once $file->path;
        } finally {
            //Restore existing prefix even if the route file throws, so the
            //prefix never leaks into subsequently registered routes.
            if ($prefix !== null) {
                $this->prefix = $previousPrefix;
            }
        }
    }
}
