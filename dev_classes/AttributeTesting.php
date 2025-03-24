<?php

namespace Lucent;

use Deprecated;

/**
 * @deprecated since version 1.5.0, use NewClass instead
 */
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


    /**
     * @deprecated use new logger instead
     */
    #[Deprecated(
        message: 'Allows for 0/0 error, please use Maths->divide() instead.',
        since: 'v0.1',
    )]
    public function divide(int $a, int $b = 1) : int
    {
        return $a/$b;
    }

    public function log(string $value) : void
    {

    }

}