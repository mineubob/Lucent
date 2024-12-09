<?php
/**
 * Copyright Jack Harris
 * Peninsula Interactive - policyManager-AuthApi
 * Last Updated - 8/11/2023
 */

namespace Lucent\Commandline;


use Lucent\Router;

class CliRouter extends Router
{


    public function registerRoute(string $uri, string $type, string $method, $controller): void
    {

        $this->routes[$type][$uri] = ["controller"=>$controller,"method"=>$method];
    }

    function loadRoutes(string $file, ?string $prefix = null): void
    {
        require_once EXTERNAL_ROOT.$file;
    }
}