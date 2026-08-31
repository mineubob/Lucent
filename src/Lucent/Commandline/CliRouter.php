<?php
declare(strict_types=1);


namespace Lucent\Commandline;

use Lucent\Facades\FileSystem;
use Lucent\Filesystem\Exceptions\FileNotFound;
use Lucent\Filesystem\File;
use Lucent\Router;

class CliRouter extends Router
{
    /**
     * Register a new route with optional controller and middleware
     */
    public function registerRoute(string $uri, string $type, string $method, ?string $controller = null, array $middleware = [], ?string $description = null): void
    {
        $uri = str_replace(":", " ", $uri);
        $this->routes[$type][$uri] = [
            "controller" => $controller,
            "method" => $method,
            "middleware" => array_merge($this->middleware, $middleware),
            "description" => $description
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
        $routes = new File($file, null, FileSystem::isAbsolute($file));

        if (!$routes->exists()) {
            throw new FileNotFound($routes->path);
        }

        require_once $routes->path;
    }
}
