<?php

namespace Lucent\Routing\Stream;

use Lucent\Http\StreamController;
use Lucent\Routing\RouteGroup;

class StreamRouteGroup extends RouteGroup
{

    private int $timeout = 30;
    private bool $abortWithUser = true;

    private bool $enableIds = false;

    public function enableIds(bool $enable = true) : self
    {
        $this->enableIds = $enable;
        return $this;
    }

    public function timeout(int $seconds) : self
    {
        $this->timeout = $seconds;
        return $this;
    }

    public function abortWithUser(bool $abort = true) : self
    {
        $this->abortWithUser = $abort;
        return $this;
    }

    /**
     * @param class-string<StreamController> $controller
     * @suppress PhanTypeInvalidCallableArraySize
     */
    public function event(string $path, string $controller) : self
    {
        return $this->registerRoute($path, 'GET', [$controller, "execute","metadata"=>["timeout"=>$this->timeout,"abortWithUser"=>$this->abortWithUser,"enableIds"=>$this->enableIds]]);
    }

    protected function buildPath(string $path): string
    {
        return $path;
    }


}