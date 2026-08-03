<?php

namespace Lucent\Routing\Rest;

use Lucent\Routing\RouteGroup;

class RestRouteGroup extends RouteGroup
{

    protected string $defaultControllerClass;
    protected ?string $prefix = null;

    public function get(string $path,  string $method, ?string $controller = null): static
    {
        if ($controller === null) {
            $controller = $this->defaultControllerClass;
        }
        return $this->registerRoute($path, 'GET', [$controller, $method]);
    }

    public function post(string $path,  string $method, ?string $controller = null): static
    {
        if ($controller === null) {
            $controller = $this->defaultControllerClass;
        }
        return $this->registerRoute($path, 'POST',  [$controller, $method]);
    }

    public function put(string $path,  string $method, ?string $controller = null): static
    {
        if ($controller === null) {
            $controller = $this->defaultControllerClass;
        }
        return $this->registerRoute($path, 'PUT', [$controller, $method]);
    }

    public function delete(string $path,  string $method, ?string $controller = null): static
    {
        if ($controller === null) {
            $controller = $this->defaultControllerClass;
        }
        return $this->registerRoute($path, 'DELETE', [$controller, $method]);
    }

    public function defaultController(string $class): static
    {
        $this->defaultControllerClass = $class;
        return $this;
    }

    public function prefix(string $prefix): static
    {
        // Append to any existing prefix (which may have been inherited from
        // an outer Router::group()) so nested group prefixes accumulate.
        $this->prefix = ($this->prefix ?? '') . '/' . ltrim($prefix, '/');
        return $this;
    }

    /**
     * Set the base prefix directly (replacement, not appending).
     * Used by RestRouteBuilder to inherit the outer Router group prefix.
     */
    public function setBasePrefix(string $prefix): static
    {
        $this->prefix = $prefix;
        return $this;
    }

    protected function buildPath(string $path): string
    {
        $prefix = $this->prefix ? rtrim($this->prefix, '/') : '';
        return $prefix ? $prefix . '/' . ltrim($path, '/') : $path;
    }
}
