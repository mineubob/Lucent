<?php

namespace Lucent\Facades;

class Request
{

    public static function input($key,$default = null) : string{
        if($_SERVER["REQUEST_METHOD"] === "POST"){
            if(array_key_exists($key,$_POST)) return $_POST[$key];
        }

        if($_SERVER["REQUEST_METHOD"] === "GET"){
            if(array_key_exists($key,$_GET)) return $_GET[$key];
        }

        return $default;
    }

}