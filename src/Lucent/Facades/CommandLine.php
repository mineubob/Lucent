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

        Application::getInstance()->getConsoleRouter()->registerRoute($command,CliRouter::$ROUTE_CLI,$method,$class);

    }

    public static function execute(string $command): void
    {

        $args = explode(" ",$command);
        array_unshift($args,"cli.php");
        $_SERVER["REQUEST_METHOD"] = Router::$ROUTE_CLI;

        Application::getInstance()->executeConsoleCommand(explode(" ",$command));

    }
}