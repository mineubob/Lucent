<?php
/**
 * Copyright Jack Harris
 * Peninsula Interactive - nextstats-auth
 * Last Updated - 7/11/2023
 */

namespace Lucent\Facades;


use Lucent\Application;
use Lucent\Commandline\MigrationController;

class App
{

    public static function Env(string $key, $default = null)
    {

        $env = Application::getInstance()->getEnv();

        if (array_key_exists($key, $env)) {
            return trim($env[$key]);
        } else {
            return $default;
        }

    }


    public static function CurrentRequest(): array
    {
        return Application::getInstance()->getResponse();
    }


    public static function RegisterRoutes(string $routeFile, $prefix = null): void
    {
        Application::getInstance()->loadRoutes($routeFile,$prefix);
    }

    public static function RegisterCommands(string $commandFile): void
    {
        Application::getInstance()->loadCommands($commandFile);
    }

    public static function Execute() : void
    {
        if(PHP_SAPI === 'cli'){
            $_SERVER["REQUEST_METHOD"] = "CLI";

            // Register default routes
            CommandLine::register("Migration make {class}", "make", MigrationController::class);

        // The user should load other routes by calling App::RegisterCommand()
            Application::getInstance()->executeConsoleCommand();
        }else{
            Application::getInstance()->executeHttpRequest();
        }
    }


}