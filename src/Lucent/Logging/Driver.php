<?php

namespace Lucent\Logging;

abstract class Driver
{
    abstract public function write(string $line) : void;

}