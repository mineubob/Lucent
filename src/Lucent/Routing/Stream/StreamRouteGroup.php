<?php

namespace Lucent\Routing\Stream;

use Lucent\Http\StreamController;
use Lucent\Routing\RouteGroup;

/**
 * Route group for Server-Sent Events (SSE) endpoints.
 *
 * @deprecated Use a regular RestRouteGroup with a controller that returns
 *             a PSR-7 Response with withEventStream() instead:
 *
 * ```php
 * Route::rest()->group("events")
 *      ->prefix("/events")
 *      ->defaultController(EventController::class)
 *      ->get(path: "/stream", method: "stream");
 * ```
 * 
 * Where EventController::stream() returns:
 * ```php
 * return (new \Lucent\Http\Message\Response())->withEventStream($generator);
 * ```
 */

class StreamRouteGroup extends RouteGroup
{

    private int $timeout = 30;
    private bool $abortWithUser = true;

    private bool $enableIds = false;

    public function enableIds(bool $enable = true) : static
    {
        $this->enableIds = $enable;
        return $this;
    }

    public function timeout(int $seconds) : static
    {
        $this->timeout = $seconds;
        return $this;
    }

    public function abortWithUser(bool $abort = true) : static
    {
        $this->abortWithUser = $abort;
        return $this;
    }

    /**
     * @param class-string<StreamController> $controller
     * @suppress PhanTypeInvalidCallableArraySize
     */
    public function event(string $path, string $controller) : static
    {
        return $this->registerRoute($path, 'GET', [$controller, "execute","metadata"=>["timeout"=>$this->timeout,"abortWithUser"=>$this->abortWithUser,"enableIds"=>$this->enableIds]]);
    }

    protected function buildPath(string $path): string
    {
        return $path;
    }


}