<?php
declare(strict_types=1);


namespace Lucent\Routing\Rest;

use Lucent\Application;
use Lucent\Routing\RouteBuilder;

class RestRouteBuilder extends RouteBuilder
{

    public function group($name): RestRouteGroup
    {
        $group = new RestRouteGroup($name, Application::getInstance()->httpRouter);

        // Inherit prefix and middleware from the outer Router::group() state
        // so that REST routes respect outer group attributes.
        $router = Application::getInstance()->httpRouter;

        $routerPrefix = $router->getPrefix();
        if ($routerPrefix !== null) {
            $group->setBasePrefix($routerPrefix);
        }

        $routerMiddleware = $router->getMiddleware();
        if (!empty($routerMiddleware)) {
            // Set the router's middleware as the base. Subsequent chained
            // ->middleware() calls on the group will replace this entirely
            // (RouteGroup::middleware() replaces, it does not merge).
            $group->middleware($routerMiddleware);
        }

        return $group;
    }
}
