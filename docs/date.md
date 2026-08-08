[Home](../../README.md)

# Date & Time in Lucent Framework

## Introduction

Lucent provides a PSR-20 compliant date and time layer built around two classes:

- **`Lucent\Date\Clock`** — an immutable, timezone-aware source of "now" implementing `Psr\Clock\ClockInterface`.
- **`Lucent\Date\Moment`** — an immutable point-in-time value object with human-friendly helpers.

## Table of Contents

- [Overview](#overview)
- [The Clock](#the-clock)
  - [Creating a Clock](#creating-a-clock)
  - [Shared Clocks](#shared-clocks)
  - [Dependency Injection](#dependency-injection)
- [The Moment](#the-moment)
  - [Creating a Moment](#creating-a-moment)
  - [Formatting](#formatting)
  - [Relative Time](#relative-time)
  - [Immutability](#immutability)
- [The Clock Facade](#the-clock-facade)

## Overview

Key features:

- PSR-20 compliant `ClockInterface` implementation
- Immutable `Clock` and `Moment` — safe to share and reuse
- Per-instance timezones (no global static state)
- Shared `local()` and `utc()` clocks
- Constructor injection of `Psr\Clock\ClockInterface` via the container

## The Clock

A `Clock` is an immutable source of the current time pinned to a single timezone.

### Creating a Clock

```php
use Lucent\Date\Clock;

$clock = new Clock(new \DateTimeZone('Australia/Sydney'));

$now = $clock->now(); // DateTimeImmutable, timezone-aware
```

The constructor accepts a `DateTimeZone` instance. `now()` always returns a `DateTimeImmutable`.

### Shared Clocks

Two shared clocks are provided for convenience:

```php
use Lucent\Date\Clock;

// The PHP runtime timezone (falls back to UTC).
$local = Clock::local();

// Always UTC — canonical for storage, logging and timestamps.
$utc = Clock::utc();
```

`Clock::local()` can be overridden at the application level:

```php
Clock::setLocal(new Clock(new \DateTimeZone('Asia/Tokyo')));
```

`Clock::utc()` is intentionally not overridable — UTC is a fixed constant.

### Dependency Injection

The shared local clock is registered in the container under both
`Psr\Clock\ClockInterface::class` and `Lucent\Date\Clock::class`, so you can
type-hint it in constructors:

```php
use Psr\Clock\ClockInterface;

class ReportService
{
    public function __construct(private ClockInterface $clock) {}

    public function generate(): void
    {
        $now = $this->clock->now();
        // ...
    }
}
```

## The Moment

A `Moment` is an immutable point-in-time value object wrapping a timezone-aware
`DateTimeImmutable`.

### Creating a Moment

```php
use Lucent\Date\Clock;
use Lucent\Date\Moment;

// Defaults to "now" in the local timezone.
$moment = new Moment();

// A specific timestamp in a specific timezone.
$moment = new Moment(1700000000, Clock::utc());

// A parseable date string.
$moment = new Moment('2023-11-14 22:13:20', Clock::utc());

// An existing DateTimeImmutable (timezone preserved).
$moment = new Moment($someDateTimeImmutable);

// Via a clock.
$moment = Clock::local()->moment();
```

### Formatting

```php
$moment->format();                       // 'F j, Y g:i A' by default
$moment->format('Y-m-d H:i:s');
$moment->format('F j, Y g:i A T');      // appends the timezone abbreviation
$moment->time();                         // Unix timestamp
$moment->toAtom();                       // RFC 3339 / ISO 8601
$moment->toIso8601();                    // same as toAtom()
```

### Relative Time

```php
$moment->ago();                          // '5 minutes ago', 'in the future'
$moment->diffForHumans();                // '5 minutes ago' / '5 minutes from now'
$moment->isPast();
$moment->isFuture();
```

### Immutability

`Moment` is immutable. Every helper returns a **new** instance and never
mutates the receiver:

```php
$original = new Moment(1700000000, Clock::utc());

$added  = $original->add(new \DateInterval('P1D')); // new Moment
$subbed = $original->sub(new \DateInterval('P1D')); // new Moment

// $original is unchanged.
```

## The Clock Facade

For quick access, a static facade is available:

```php
use Lucent\Facades\Clock;

Clock::now();        // DateTimeImmutable
Clock::moment();     // Moment
Clock::local();      // shared local Clock
Clock::utc();        // shared UTC Clock
```
