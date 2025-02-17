<?php

namespace Lucent\Routing;

use Lucent\Application;

class RestRouteBuilder extends RouteBuilder
{

    function group($name): RestRouteGroup
    {
       return new RestRouteGroup($name,Application::getInstance()->httpRouter);
    }

}