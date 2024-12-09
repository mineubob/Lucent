<?php

namespace Lucent;


use Lucent\Http\Request;

abstract class Middleware
{

    abstract public function handle(Request $request): Request;

}