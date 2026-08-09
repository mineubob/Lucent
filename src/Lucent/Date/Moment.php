<?php

namespace Lucent\Date;

use DateInterval;
use DateTimeImmutable;

/**
 * An immutable point-in-time value object.
 *
 * Wraps a timezone-aware {@see DateTimeImmutable}. All helpers return a NEW
 * Moment and never mutate the receiver.
 *
 * @package Lucent\Date
 */
final readonly class Moment
{
    public DateTimeImmutable $dateTime;

    /**
     * @param DateTimeImmutable|string|int|null $value A DateTimeImmutable, a
     *        parseable date string, or a Unix timestamp. Defaults to "now".
     * @param Clock|null $clock The clock whose timezone is applied when
     *        $value is a string or timestamp. Defaults to {@see Clock::local()}.
     */
    public function __construct(
        DateTimeImmutable|string|int|null $value = null,
        ?Clock $clock = null,
    ) {
        $clock ??= Clock::local();

        $this->dateTime = match (true) {
            $value instanceof DateTimeImmutable => $value,
            is_int($value) => (new DateTimeImmutable('@' . $value))->setTimezone($clock->getTimezone()),
            is_string($value) => new DateTimeImmutable($value, $clock->getTimezone()),
            default => $clock->now(),
        };
    }

    /**
     * Formats the moment.
     *
     * @param string $format PHP date format string. Append `T` to include
     *        the timezone abbreviation, e.g. `'F j, Y g:i A T'`.
     */
    public function format(string $format = 'F j, Y g:i A'): string
    {
        return $this->dateTime->format($format);
    }

    /**
     * The Unix timestamp of this moment.
     */
    public function time(): int
    {
        return $this->dateTime->getTimestamp();
    }

    /**
     * A human-friendly relative description of this moment.
     *
     * Handles both past and future moments.
     */
    public function diffForHumans(?DateTimeImmutable $now = null): string
    {
        $now ??= Clock::utc()->now();

        if ($this->dateTime < $now) {
            return $this->relative($this->dateTime->diff($now), 'ago');
        }

        return $this->relative($now->diff($this->dateTime), 'from now');
    }

    /**
     * Whether this moment is in the past.
     */
    public function isPast(?DateTimeImmutable $now = null): bool
    {
        return $this->dateTime < ($now ?? Clock::utc()->now());
    }

    /**
     * Whether this moment is in the future.
     */
    public function isFuture(?DateTimeImmutable $now = null): bool
    {
        return $this->dateTime > ($now ?? Clock::utc()->now());
    }

    /**
     * Returns a NEW Moment with the given interval added.
     */
    public function add(DateInterval $interval): self
    {
        return new self($this->dateTime->add($interval));
    }

    /**
     * Returns a NEW Moment with the given interval subtracted.
     */
    public function sub(DateInterval $interval): self
    {
        return new self($this->dateTime->sub($interval));
    }

    /**
     * RFC 3339 / ISO 8601 representation.
     */
    public function toAtom(): string
    {
        return $this->dateTime->format(DateTimeImmutable::ATOM);
    }

    /**
     * ISO 8601 representation.
     *
     * Delegates to {@see toAtom()} — `DateTimeImmutable::ISO8601` is
     * deprecated and not actually ISO-8601 compliant.
     */
    public function toIso8601(): string
    {
        return $this->toAtom();
    }

    /**
     * Backwards-compatible relative description (past only).
     */
    public function ago(): string
    {
        $diff = $this->dateTime->diff(Clock::utc()->now());

        if ($diff->invert) {
            return 'in the future';
        }

        return $this->relative($diff, 'ago');
    }

    public function __toString(): string
    {
        return $this->format();
    }

    /**
     * Builds a relative phrase from a DateInterval.
     */
    private function relative(\DateInterval $diff, string $suffix): string
    {
        $periods = [
            'y' => 'year',
            'm' => 'month',
            'd' => 'day',
            'h' => 'hour',
            'i' => 'minute',
        ];

        foreach ($periods as $unit => $label) {
            if ($diff->{$unit} > 0) {
                $value = $diff->{$unit};
                return $value . ' ' . $label . ($value > 1 ? 's' : '') . ' ' . $suffix;
            }
        }

        return 'just now';
    }
}