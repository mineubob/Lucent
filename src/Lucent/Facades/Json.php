<?php

namespace Lucent\Facades;


use Lucent\Http\JsonResponse;

class Json
{

    public static function response() : JsonResponse
    {
        return new JsonResponse();
    }

}