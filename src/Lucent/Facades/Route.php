<?php
/**
 * Copyright Jack Harris
 * Peninsula Interactive - forum
 * Last Updated - 9/09/2023
 */

namespace Lucent\Facades;


use Lucent\Application;
use Lucent\Http\HttpResponse;
use Lucent\Routing\RestRouteBuilder;

class Route
{

    public static function rest() : RestRouteBuilder{
        return new RestRouteBuilder(Application::getInstance()->httpRouter);
    }

    public static function error(int $code, HttpResponse $response) : void
    {
        Application::getInstance()->registerErrorTemplate($code,$response);
    }

    public static function fallback(HttpResponse $response) : void
    {
        Application::getInstance()->registerFallback($response);

    }

}