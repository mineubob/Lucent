<?php
/**
 * Copyright Jack Harris
 * Peninsula Interactive - policyManager-AuthApi
 * Last Updated - 8/11/2023
 */

namespace Lucent\Facades;

use Lucent\Application;
use Lucent\Commandline\CliRouter;
use Lucent\Router;

class CommandLine
{

    public static function register(string $command, string $method, $class): void
    {

        Application::getInstance()->consoleRouter->registerRoute($command,CliRouter::$ROUTE_CLI,$method,$class);

    }

    public static function execute(string $command): string
    {

        $args = explode(" ",$command);
        $_SERVER["REQUEST_METHOD"] = Router::$ROUTE_CLI;

        return Application::getInstance()->executeConsoleCommand($args);

    }
}