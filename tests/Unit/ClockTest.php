<?php

namespace Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use Lucent\Date\Clock;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

class ClockTest extends TestCase
{
    protected function tearDown(): void
    {
        // Reset the shared local clock so tests don't leak state.
        Clock::setLocal();
    }

    public function test_implements_psr_clock_interface(): void
    {
        $this->assertInstanceOf(ClockInterface::class, new Clock(new DateTimeZone('UTC')));
    }

    public function test_now_returns_datetime_immutable(): void
    {
        $clock = new Clock(new DateTimeZone('UTC'));
        $this->assertInstanceOf(DateTimeImmutable::class, $clock->now());
    }

    public function test_now_respects_constructor_timezone(): void
    {
        $clock = new Clock(new DateTimeZone('Australia/Sydney'));
        $this->assertSame('Australia/Sydney', $clock->now()->getTimezone()->getName());
    }

    public function test_get_timezone_returns_configured_timezone(): void
    {
        $clock = new Clock(new DateTimeZone('Europe/London'));
        $this->assertSame('Europe/London', $clock->getTimezone()->getName());
    }

    public function test_with_timezone_returns_new_clock_and_does_not_mutate(): void
    {
        $clock = new Clock(new DateTimeZone('UTC'));
        $changed = $clock->withTimezone('America/New_York');

        $this->assertNotSame($clock, $changed);
        $this->assertSame('UTC', $clock->getTimezone()->getName());
        $this->assertSame('America/New_York', $changed->getTimezone()->getName());
    }

    public function test_local_uses_runtime_timezone(): void
    {
        $clock = Clock::local();
        $this->assertSame(date_default_timezone_get() ?: 'UTC', $clock->getTimezone()->getName());
    }

    public function test_local_returns_same_shared_instance(): void
    {
        $this->assertSame(Clock::local(), Clock::local());
    }

    public function test_set_local_overrides_shared_clock(): void
    {
        $custom = new Clock(new DateTimeZone('Asia/Tokyo'));
        Clock::setLocal($custom);

        $this->assertSame($custom, Clock::local());
    }

    public function test_set_local_null_resets_to_runtime_timezone(): void
    {
        Clock::setLocal(new Clock(new DateTimeZone('Asia/Tokyo')));
        Clock::setLocal();

        $this->assertSame(date_default_timezone_get() ?: 'UTC', Clock::local()->getTimezone()->getName());
    }

    public function test_utc_is_pinned_to_utc(): void
    {
        $this->assertSame('UTC', Clock::utc()->getTimezone()->getName());
    }

    public function test_utc_returns_same_shared_instance(): void
    {
        $this->assertSame(Clock::utc(), Clock::utc());
    }

    public function test_moment_returns_moment(): void
    {
        $clock = new Clock(new DateTimeZone('UTC'));
        $this->assertInstanceOf(\Lucent\Date\Moment::class, $clock->moment());
    }
}