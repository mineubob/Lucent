<?php

namespace Lucent\Date;

use DateTimeZone;

class Date
{

    private static DateTimeZone $userTimezone;

    public static function setTimezone(string $timezone): void
    {
        self::$userTimezone = new DateTimeZone($timezone);
    }

    public static function getTimezone(): DateTimeZone
    {
        if(!isset(self::$userTimezone)) {
            self::$userTimezone = new DateTimeZone('UTC');
        }

        return self::$userTimezone;
    }

    public static function instance(?int $timestamp = null) : Instance
    {
        return new Instance($timestamp);
    }


}