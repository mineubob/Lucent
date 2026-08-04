<?php
/**
 * Copyright Jack Harris
 * Peninsula Interactive - forum
 * Last Updated - 9/09/2023
 */

namespace Lucent\Facades;


use Lucent\Application;
use Lucent\Http\HttpResponse;
use Psr\Http\Message\ResponseInterface;
use Lucent\Routing\Rest\RestRouteBuilder;
use Lucent\Routing\Stream\StreamRouteBuilder;

class Route
{

    public static function disable(string $route) : void
    {
        Application::getInstance()->httpRouter->disable($route);
    }

    public static function rest() : RestRouteBuilder
    {
        return new RestRouteBuilder(Application::getInstance()->httpRouter);
    }

    public static function stream() :  StreamRouteBuilder
    {
        return new StreamRouteBuilder(Application::getInstance()->httpRouter);
    }

    public static function error(int $code, HttpResponse|ResponseInterface $response) : void
    {
        Application::getInstance()->registerErrorTemplate($code,$response);
    }

    public static function fallback(HttpResponse|ResponseInterface $response) : void
    {
        Application::getInstance()->registerFallback($response);
    }

}