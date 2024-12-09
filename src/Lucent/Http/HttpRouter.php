<?php
/**
 * Copyright Jack Harris
 * Peninsula Interactive - forum
 * Last Updated - 9/09/2023
 */

namespace Lucent\Http;


use Lucent\Router;

class HttpRouter extends Router
{


    public function registerRoute(string $uri, string $type, string $method, $controller): void
    {

        $this->routes[$type][$this->prefix.$uri] = ["controller"=>$controller,"method"=>$method,"middleware"=>$this->middleware];
    }

    function loadRoutes(string $file, string|null $prefix = null): void
    {
        require_once EXTERNAL_ROOT.$file;
    }
}