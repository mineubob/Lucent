<?php

namespace Lucent\Routing;

use Lucent\Router;

abstract class RouteBuilder
{
    protected Router $router;

    public function __construct(Router $router)
    {
        $this->router = $router;
    }

    abstract function group($name) : RouteGroup;
}