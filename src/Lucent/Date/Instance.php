<?php

namespace Lucent\Date;

use DateTime;

class Instance
{
    public private(set) int $time;
    public private(set) DateTime $dateTime;

    private bool $withTimezone = false;

    public function __construct(?int $timestamp = null)
    {
        if ($timestamp === null) {
            $timestamp = time();
        }

        $this->time = $timestamp;
        $this->dateTime = new DateTime("@" . $this->time);
        $this->dateTime->setTimezone(Date::getTimezone());
    }

    public function format(string $format = 'F j, Y g:i A'): string
    {
        if($this->withTimezone){
            return $this->dateTime->format($format." T");
        }
        return $this->dateTime->format($format);
    }

    public function withTimezone(bool $bool = true) : Instance
    {
        $this->withTimezone = $bool;
        return $this;
    }

    public function ago(): string
    {
        $diff = time() - $this->time; // Use instance timestamp

        if ($diff < 0) return 'in the future';

        $periods = [
            31536000 => 'year',
            2592000  => 'month',
            86400    => 'day',
            3600     => 'hour',
            60       => 'minute'
        ];

        foreach ($periods as $seconds => $period) {
            if ($diff >= $seconds) {
                $value = floor($diff / $seconds);
                return $value . ' ' . $period . ($value > 1 ? 's' : '') . ' ago';
            }
        }

        return 'just now';
    }

    public function __toString(): string
    {
        return $this->format();
    }
}