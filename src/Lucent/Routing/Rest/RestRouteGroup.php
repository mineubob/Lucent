<?php

namespace Lucent\Routing\Rest;

use Lucent\Routing\RouteGroup;

class RestRouteGroup extends RouteGroup
{

    protected string $defaultControllerClass;
    protected ?string $prefix = null;

    public function get(string $path,  string $method ,?string $controller = null): self
    {
        if($controller === null){
            $controller = $this->defaultControllerClass;
        }
        return $this->registerRoute($path, 'GET', [$controller, $method]);
    }

    public function post(string $path,  string $method ,?string $controller = null): self
    {
        if($controller === null){
            $controller = $this->defaultControllerClass;
        }
        return $this->registerRoute($path, 'POST',  [$controller, $method]);
    }

    public function put(string $path,  string $method ,?string $controller = null): self
    {
        if($controller === null){
            $controller = $this->defaultControllerClass;
        }
        return $this->registerRoute($path, 'PUT',[$controller, $method]);
    }

    public function delete(string $path,  string $method ,?string $controller = null): self
    {
        if($controller === null){
            $controller = $this->defaultControllerClass;
        }
        return $this->registerRoute($path, 'DELETE', [$controller, $method]);
    }

    public function defaultController(string $class): self
    {
        $this->defaultControllerClass = $class;
        return $this;
    }

    public function prefix(string $prefix): self
    {
        $this->prefix = $prefix;
        return $this;
    }

    protected function buildPath(string $path) : string
    {
        return $this->prefix ? $this->prefix . '/' . ltrim($path, '/') : $path;
    }

}