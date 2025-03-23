<?php

namespace Lucent;

use Deprecated;

class AttributeTesting
{

    #[Deprecated(
        message: 'Contains some security issue.',
        since: 'v0.1',
    )]
    public function multiply(int $a, int $b) : int
    {
        return $a*$b;
    }

    #[Deprecated(
        message: 'Allows for 0/0 error, please use Maths->divide() instead.',
        since: 'v0.1',
    )]
    public function divide(int $a, int $b) : int
    {
        return $a/$b;
    }

}