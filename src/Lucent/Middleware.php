<?php

namespace Lucent;


use Lucent\Http\Request;

abstract class Middleware
{

    /**
     * @deprecated Implement Psr\Http\Server\MiddlewareInterface instead.
     */
    abstract public function handle(Request $request): Request;

}