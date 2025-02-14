<?php
/**
 * Copyright Jack Harris
 * Peninsula Interactive - nextstats-auth
 * Last Updated - 7/11/2023
 */

namespace Lucent\Facades;


use Lucent\Application;
use Lucent\Commandline\MigrationController;
use Phar;

class App
{

    public static function env(string $key, $default = null)
    {

        $env = Application::getInstance()->getEnv();

        if (array_key_exists($key, $env)) {
            return trim($env[$key]);
        } else {
            return $default;
        }

    }

    public static function getLucentVersion() : ?string
    {
        $currentPharPath = Phar::running(false);
        $phar = new Phar($currentPharPath);
        $metadata = $phar->getMetadata();

        return $metadata['version'] ?? null;
    }


    public static function registerRoutes(string $routeFile, $prefix = null): void
    {
        Application::getInstance()->loadRoutes($routeFile,$prefix);
    }

    public static function registerCommands(string $commandFile): void
    {
        Application::getInstance()->loadCommands($commandFile);
    }

    public static function execute() : string
    {
        return Application::getInstance()->executeHttpRequest();
    }


}