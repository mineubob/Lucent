<?php

namespace Lucent\Facades;

use DateTimeImmutable;
use Lucent\Date\Moment;

/**
 * Clock Facade
 *
 * Provides static access to the PSR-20 clock.
 *
 * @package Lucent\Facades
 */
class Clock
{
    /**
     * The current time as a timezone-aware DateTimeImmutable.
     */
    public static function now(): DateTimeImmutable
    {
        return \Lucent\Date\Clock::local()->now();
    }

    /**
     * Creates a Moment value object.
     *
     * @param int|null $timestamp Unix timestamp; defaults to "now".
     */
    public static function moment(?int $timestamp = null): Moment
    {
        return \Lucent\Date\Clock::local()->moment($timestamp);
    }

    /**
     * The shared clock pinned to the PHP runtime timezone.
     */
    public static function local(): \Lucent\Date\Clock
    {
        return \Lucent\Date\Clock::local();
    }

    /**
     * The shared clock pinned to UTC.
     */
    public static function utc(): \Lucent\Date\Clock
    {
        return \Lucent\Date\Clock::utc();
    }
}