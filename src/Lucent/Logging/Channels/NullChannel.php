<?php
declare(strict_types=1);


namespace Lucent\Logging\Channels;

use Lucent\Logging\Channel;
use Lucent\Logging\Drivers\NullDriver;

/**
 * A PSR-3 logger that discards everything.
 *
 * log() is overridden as a true no-op (mirroring PSR-3's NullLogger intent):
 * no formatting, interpolation, or timestamp work is performed before
 * discarding. Level validation is also skipped — nothing is ever written,
 * so an unknown level is harmless here.
 */
class NullChannel extends Channel
{
    public function __construct()
    {
        parent::__construct('null', new NullDriver());
    }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        // Intentionally empty — true no-op per PSR-3 NullLogger convention.
    }
}