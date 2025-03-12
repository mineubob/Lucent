<?php

namespace Lucent\Facades;


use Lucent\Http\JsonResponse;

#[\Deprecated()]
class Json
{

    public static function response() : JsonResponse
    {
        return new JsonResponse();
    }

}