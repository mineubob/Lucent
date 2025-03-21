<?php

namespace Lucent\Facades;

use Lucent\Application;

class Regex
{

    public static function set(string $key, string $pattern, ?string $message = null): void
    {
        Application::getInstance()->addRegex($key, $pattern, $message);
    }

    public static function all() : array
    {
        return Application::getInstance()->getRegexRules();
    }

}