<?php

namespace Lucent\Routing;

use Lucent\Router;

abstract class RouteGroup
{
    protected string $name;
    protected array $middleware = [];
    protected Router $router;


    public function __construct(string $name, Router $router)
    {
        $this->name = $name;
        $this->router = $router;
    }

    public function middleware(array $middleware): self
    {
        $this->middleware = $middleware;
        return $this;
    }

    protected function registerRoute(string $path, string $type, array $handler): self
    {
        $fullPath = $this->buildPath($path);
        $this->router->registerRoute($fullPath, $type, $handler[1], $handler[0], $this->middleware);
        return $this;
    }

    abstract protected function buildPath(string $path): string;

}