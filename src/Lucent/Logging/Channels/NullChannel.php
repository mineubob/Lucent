<?php

namespace Lucent\Logging\Channels;

use Lucent\Logging\Channel;
use Lucent\Logging\Drivers\NullDriver;

class NullChannel extends Channel
{
    public function __construct()
    {
        parent::__construct('null', new NullDriver());
    }

}