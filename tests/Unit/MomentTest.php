<?php

namespace Tests\Unit;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Lucent\Date\Clock;
use Lucent\Date\Moment;
use PHPUnit\Framework\TestCase;

class MomentTest extends TestCase
{
    protected function tearDown(): void
    {
        Clock::setLocal();
    }

    public function test_defaults_to_local_clock(): void
    {
        $moment = new Moment();
        $this->assertSame(Clock::local()->getTimezone()->getName(), $moment->dateTime->getTimezone()->getName());
    }

    public function test_accepts_datetime_immutable(): void
    {
        $dt = new DateTimeImmutable('@1700000000', new DateTimeZone('UTC'));
        $moment = new Moment($dt);
        $this->assertSame($dt, $moment->dateTime);
    }

    public function test_accepts_string(): void
    {
        $moment = new Moment('2023-11-14 22:13:20', Clock::utc());
        $this->assertSame(1700000000, $moment->dateTime->getTimestamp());
    }

    public function test_accepts_timestamp_and_applies_clock_timezone(): void
    {
        $moment = new Moment(1700000000, new Clock(new DateTimeZone('Australia/Sydney')));
        $this->assertSame('Australia/Sydney', $moment->dateTime->getTimezone()->getName());
        $this->assertSame(1700000000, $moment->dateTime->getTimestamp());
    }

    public function test_format_default(): void
    {
        $moment = new Moment(1700000000, Clock::utc());
        $this->assertSame('November 14, 2023 10:13 PM', $moment->format());
    }

    public function test_format_with_t_appends_timezone_abbreviation(): void
    {
        $moment = new Moment(1700000000, Clock::utc());
        $this->assertStringEndsWith(' UTC', $moment->format('F j, Y g:i A T'));
    }

    public function test_time_returns_unix_timestamp(): void
    {
        $moment = new Moment(1700000000, Clock::utc());
        $this->assertSame(1700000000, $moment->time());
    }

    public function test_ago_just_now(): void
    {
        $moment = new Moment(time(), Clock::utc());
        $this->assertSame('just now', $moment->ago());
    }

    public function test_ago_minutes(): void
    {
        $moment = new Moment(time() - 300, Clock::utc());
        $this->assertSame('5 minutes ago', $moment->ago());
    }

    public function test_ago_hours(): void
    {
        $moment = new Moment(time() - 7200, Clock::utc());
        $this->assertSame('2 hours ago', $moment->ago());
    }

    public function test_ago_days(): void
    {
        $moment = new Moment(time() - 172800, Clock::utc());
        $this->assertSame('2 days ago', $moment->ago());
    }

    public function test_ago_future(): void
    {
        $moment = new Moment(time() + 3600, Clock::utc());
        $this->assertSame('in the future', $moment->ago());
    }

    public function test_diff_for_humans_past(): void
    {
        $now = new DateTimeImmutable('@1700000000', new DateTimeZone('UTC'));
        $moment = new Moment(1700000000 - 300, Clock::utc());
        $this->assertSame('5 minutes ago', $moment->diffForHumans($now));
    }

    public function test_diff_for_humans_future(): void
    {
        $now = new DateTimeImmutable('@1700000000', new DateTimeZone('UTC'));
        $moment = new Moment(1700000000 + 300, Clock::utc());
        $this->assertSame('5 minutes from now', $moment->diffForHumans($now));
    }

    public function test_is_past_and_is_future(): void
    {
        $now = new DateTimeImmutable('@1700000000', new DateTimeZone('UTC'));

        $this->assertTrue((new Moment(1700000000 - 60, Clock::utc()))->isPast($now));
        $this->assertFalse((new Moment(1700000000 - 60, Clock::utc()))->isFuture($now));
        $this->assertTrue((new Moment(1700000000 + 60, Clock::utc()))->isFuture($now));
        $this->assertFalse((new Moment(1700000000 + 60, Clock::utc()))->isPast($now));
    }

    public function test_add_returns_new_moment(): void
    {
        $moment = new Moment(1700000000, Clock::utc());
        $added = $moment->add(new DateInterval('P1D'));

        $this->assertNotSame($moment, $added);
        $this->assertSame(1700000000, $moment->dateTime->getTimestamp());
        $this->assertSame(1700000000 + 86400, $added->dateTime->getTimestamp());
    }

    public function test_sub_returns_new_moment(): void
    {
        $moment = new Moment(1700000000, Clock::utc());
        $subtracted = $moment->sub(new DateInterval('P1D'));

        $this->assertNotSame($moment, $subtracted);
        $this->assertSame(1700000000, $moment->dateTime->getTimestamp());
        $this->assertSame(1700000000 - 86400, $subtracted->dateTime->getTimestamp());
    }

    public function test_to_atom(): void
    {
        $moment = new Moment(1700000000, Clock::utc());
        $this->assertSame('2023-11-14T22:13:20+00:00', $moment->toAtom());
    }

    public function test_to_iso8601_matches_atom(): void
    {
        $moment = new Moment(1700000000, Clock::utc());
        $this->assertSame($moment->toAtom(), $moment->toIso8601());
    }

    public function test_to_string_uses_format(): void
    {
        $moment = new Moment(1700000000, Clock::utc());
        $this->assertSame($moment->format(), (string) $moment);
    }
}