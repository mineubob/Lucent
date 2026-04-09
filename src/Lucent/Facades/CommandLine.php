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
    private static bool $captureOutput = false;

    public static function captureOutput(bool $capture = true): void
    {
        self::$captureOutput = $capture;
    }

    public static function isCaptured(): bool
    {
        return self::$captureOutput;
    }

    public static function disableCommand(array|string $command) : void
    {
        Application::getInstance()->consoleRouter->disable($command);
    }

    public static function register(string $command, string $method, $class, ?string $description = null): void
    {
        Application::getInstance()->consoleRouter->registerRoute($command, CliRouter::$ROUTE_CLI, $method, $class, [], $description);
    }

    public static function execute(string $command): string
    {
        $args = explode(" ", $command);
        $_SERVER["REQUEST_METHOD"] = Router::$ROUTE_CLI;
        return Application::getInstance()->executeConsoleCommand($args);
    }
}