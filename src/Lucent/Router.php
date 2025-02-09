<?php
/**
 * Copyright Jack Harris
 * Peninsula Interactive - policyManager-AuthApi
 * Last Updated - 8/11/2023
 */

namespace Lucent;

abstract class Router
{


    public static string $ROUTE_POST = "POST";
    public static string $ROUTE_GET = "GET";
    public static string $ROUTE_PATCH = "PATCH";
    public static string $ROUTE_DELETE = "DELETE";
    public static string $ROUTE_CLI = "CLI";

    protected array $routes;

    protected array $middleware = [];

    protected ?string $prefix = null;


    abstract function registerRoute(string $uri, string $type, string $method, $controller);

    abstract function loadRoutes(string $file,?string $prefix = null);

    protected function CheckForVariable(string $section): bool
    {
        $outcome = false;

        if(str_starts_with($section, '{') && str_ends_with($section, '}')){

            $outcome = true;

        }

        return $outcome;
    }

    public function GetUriAsArray(?string $url = null, string $separator = "/"): array
    {
        if($url === null){
            $url = $_SERVER["REQUEST_URI"];
        }

        if(strpos($url,"?")){
            $url = substr($url, 0, strrpos($url,"?"));
        }

        return array_filter(explode($separator, $url));
    }

    public function analyseRouteAndLookup(array $route): array
    {

        $uri = $route;

        $response = [
            "route"=>null,
            "outcome"=>false
        ];

        if(!isset($this->routes[$_SERVER["REQUEST_METHOD"]])){
            return $response;
        }

        $routes = $this->routes[$_SERVER["REQUEST_METHOD"]];
        $separator = "/";

        if($_SERVER["REQUEST_METHOD"] === Router::$ROUTE_CLI){
            $separator = " ";
            array_shift($uri);
        }

        foreach ($routes as $key => $route){

            $routeKey = $this->GetUriAsArray($key,$separator);
            $variables = [];
            $checks = [1=>false];

            if(count($routeKey) === count($uri)){

                if($_SERVER["REQUEST_METHOD"] === Router::$ROUTE_CLI){
                    $count  = 0;
                }else{
                    $count  = 1;
                }

                foreach ($routeKey as $section){

                    if(!$this->CheckForVariable($section)){

                        if($uri[$count] === $section){
                            $checks[$count] = true;
                        }else{
                            $checks[$count] = false;
                        }
                    }else{
                        $variables[ltrim(rtrim($section,'}'),'{')] = $uri[$count];
                    }
                    $count++;
                }
            }

            //if we only have one section then return based on the first check.
            //Not sure why i need this, but it was causing an error
            if(count($routeKey) === 1){
                if(isset($checks[0])){
                    $checks = [$checks[0]];
                }
            }

            if(!in_array(false,$checks,true)){

                if(!array_key_exists("middleware",$route)){
                    $route["middleware"] = [];
                }

                $response["route"] = $key;
                $response["outcome"] = true;
                $response["controller"] = $route["controller"];
                $response["method"] =  $route["method"];
                $response["variables"] = $variables;
                $response["middleware"] = $route["middleware"];

                break;
            }
        }


        return $response;
    }

    public function setActiveMiddleware(array $middleware): void
    {
        $this->middleware = $middleware;
    }

    public function setPrefix(?string $prefix): void
    {
        $this->prefix = $prefix;
    }

}