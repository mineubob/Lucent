<?php

namespace Lucent\Routing;

use Lucent\Router;

abstract class RouteGroup
{
    protected string $name;
    protected ?string $prefix = null;
    protected array $middleware = [];
    protected Router $router;

    protected string $defaultControllerClass;

    public function __construct(string $name, Router $router)
    {
        $this->name = $name;
        $this->router = $router;
    }

    public function prefix(string $prefix): self
    {
        $this->prefix = $prefix;
        return $this;
    }

    public function middleware(array $middleware): self
    {
        $this->middleware = $middleware;
        return $this;
    }


    public function defaultController(string $class): self
    {
        $this->defaultControllerClass = $class;
        return $this;
    }

    protected function registerRoute(string $path, string $type, array $handler): self
    {
        $fullPath = $this->buildPath($path);
        $this->router->registerRoute($fullPath, $type, $handler[1], $handler[0], $this->middleware);
        return $this;
    }

    protected function buildPath(string $path): string
    {
        return $this->prefix ? $this->prefix . '/' . ltrim($path, '/') : $path;
    }

}