<?php

namespace Lucent\Http;

class Session
{

    public function keep(string $key,$value): void
    {
        $_SESSION[$key] = $value;
    }

    public function forget($key): void
    {
        unset($_SESSION[$key]);
    }

    public function get($key, $default = null) {
        if(!array_key_exists($key,$_SESSION)){
            return $default;
        }
        return $_SESSION[$key];
    }

}