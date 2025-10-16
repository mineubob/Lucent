<?php

namespace Lucent\Routing\Stream;

use Lucent\Application;
use Lucent\Routing\RouteBuilder;
use Lucent\Routing\RouteGroup;

class StreamRouteBuilder extends RouteBuilder
{

    function group($name): RouteGroup
    {
        return new StreamRouteGroup($name,Application::getInstance()->httpRouter);
    }
}