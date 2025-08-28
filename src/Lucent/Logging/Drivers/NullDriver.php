<?php

namespace Lucent\Logging\Drivers;

use Lucent\Logging\Driver;

class NullDriver extends Driver
{

    public function write(string $line): void
    {
        //Do nothing
    }
}