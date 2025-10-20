<?php
/**
 * Copyright Jack Harris
 * Peninsula Interactive - nextstats-auth
 * Last Updated - 7/11/2023
 */

namespace Lucent\Facades;


use Lucent\Application;
use Lucent\Database\DatabaseInterface;
use Lucent\Middleware;
use Lucent\Service;
use Phar;

class App
{
    private static ?Service $service;

    public static function env(string $key, $default = null)
    {

        $env = Application::getInstance()->getEnv();

        if (array_key_exists($key, $env)) {
            return trim($env[$key]);
        } else {
            return $default;
        }

    }

    public static function currentRoute() : array
    {
        return Application::getInstance()->httpRouter->getUriAsArray($_SERVER['REQUEST_URI']);
    }

    public static function getLucentVersion() : string
    {
        return VERSION;
    }


    public static function registerRoutes(string $routeFile): void
    {
        Application::getInstance()->loadRoutes($routeFile);
    }

    public static function registerCommands(string $commandFile): void
    {
        Application::getInstance()->loadCommands($commandFile);
    }

    public static function registerDatabaseDriver(string $key, string $driverClass) : void
    {
        Application::getInstance()->registerDatabaseDriver($key, $driverClass);
    }

    public static function registerGlobalMiddlewares(Middleware|string $middleware): void
    {
        Application::getInstance()->registerGlobalMiddleware($middleware);
    }

    public static function handleHttpRequest(): \Lucent\Http\HttpResponse {
        return Application::getInstance()->handleHttpRequest();
    }

    public static function execute() : string
    {
        return Application::getInstance()->executeHttpRequest();
    }

    public static function service() : Service
    {
        if(!isset(self::$service)) {
            self::$service = new Service();
        }
        return self::$service;
    }


}