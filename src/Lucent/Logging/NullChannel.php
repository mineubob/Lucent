<?php

namespace Lucent\Logging;

class NullChannel extends Channel
{
    public function __construct()
    {
        parent::__construct('null', 'null');
    }

    // Override all logging methods to do nothing
    public function emergency(string $message): void {}
    public function alert(string $message): void {}
    public function critical(string $message): void {}
    public function error(string $message): void {}
    public function warning(string $message): void {}
    public function notice(string $message): void {}
    public function info(string $message): void {}
    public function debug(string $message): void {}
}