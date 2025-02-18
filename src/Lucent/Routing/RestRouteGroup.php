<?php

namespace Lucent\Routing;

class RestRouteGroup extends RouteGroup
{
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
}