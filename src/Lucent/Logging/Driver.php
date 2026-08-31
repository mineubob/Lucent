<?php
declare(strict_types=1);


namespace Lucent\Logging;

abstract class Driver
{
    abstract public function write(string $line) : void;

}