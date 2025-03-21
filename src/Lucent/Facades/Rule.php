<?php

namespace Lucent\Facades;

use Lucent\Application;

class Rule
{

    public static function overrideMessage(string $rule, string $message) : void
    {
        Application::getInstance()->overrideValidationMessage($rule, $message);
    }

}