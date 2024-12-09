<?php

namespace Lucent\Facades;


use Lucent\Http\ViewResponse;
use Lucent\Bag;

class View
{
    private static Bag $bag;

    public static function Bag(): Bag
    {
        if(!isset(self::$bag)){
            self::$bag = new Bag();
        }
        return self::$bag;
    }

    public static function load(string $path, string $layout = "", array $variables = []): ViewResponse
    {
        $viewResponse = new ViewResponse($path);
        $viewResponse->setLayout($layout);


        return $viewResponse;
    }

}