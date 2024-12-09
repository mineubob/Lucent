<?php

namespace Lucent\Facades;


use Lucent\Application;
use Lucent\Logging\Channel;

class Log
{

    public static function channel(string $name): Channel
    {
        return Application::getInstance()->getLoggingChannel($name);
    }


}