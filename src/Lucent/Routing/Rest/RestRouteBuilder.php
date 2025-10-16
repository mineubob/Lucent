<?php

namespace Lucent\Routing\Rest;

use Lucent\Application;
use Lucent\Routing\RouteBuilder;

class RestRouteBuilder extends RouteBuilder
{

    public function group($name): RestRouteGroup
    {
       return new RestRouteGroup($name,Application::getInstance()->httpRouter);
    }

}