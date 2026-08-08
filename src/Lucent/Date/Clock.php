<?php

namespace Lucent\Date;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface;

/**
 * PSR-20 clock implementation.
 *
 * A clock is an immutable source of "now" pinned to a single timezone.
 * Because instances are immutable, the shared {@see self::local()} and
 * {@see self::utc()} singletons are safe to reuse across the application.
 *
 * @package Lucent\Date
 */
final class Clock implements ClockInterface
{
    private static ?Clock $local = null;

    private static ?Clock $utc = null;

    public function __construct(
        private readonly DateTimeZone $timezone,
    ) {
    }

    /**
     * Returns the current time as a timezone-aware DateTimeImmutable.
     */
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', $this->timezone);
    }

    /**
     * The timezone this clock is pinned to.
     */
    public function getTimezone(): DateTimeZone
    {
        return $this->timezone;
    }

    /**
     * Returns a NEW clock pinned to the given timezone.
     *
     * The current clock is never mutated.
     */
    public function withTimezone(DateTimeZone|string $timezone): self
    {
        return new self($timezone instanceof DateTimeZone ? $timezone : new DateTimeZone($timezone));
    }

    /**
     * Creates a {@see Moment} value object from this clock.
     *
     * @param int|null $timestamp Unix timestamp; defaults to "now".
     */
    public function moment(?int $timestamp = null): Moment
    {
        return new Moment($timestamp, $this);
    }

    /**
     * The shared clock pinned to the PHP runtime timezone.
     *
     * Falls back to UTC when no default timezone is configured.
     */
    public static function local(): self
    {
        return self::$local ??= new self(new DateTimeZone(date_default_timezone_get() ?: 'UTC'));
    }

    /**
     * Replaces the shared local clock, or resets it to the runtime timezone
     * default when `null` is passed.
     *
     * This is the only intentional mutation point; individual Clock
     * instances remain immutable.
     */
    public static function setLocal(?self $clock = null): void
    {
        self::$local = $clock;
    }

    /**
     * The shared clock pinned to UTC.
     *
     * Not overridable — UTC is a fixed constant. Useful as the canonical
     * clock for storage, logging and timestamps, and as a deterministic
     * default in tests.
     */
    public static function utc(): self
    {
        return self::$utc ??= new self(new DateTimeZone('UTC'));
    }
}