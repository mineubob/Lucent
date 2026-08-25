[Home](../../README.md)

# Date & Time in Lucent Framework

## Introduction

Lucent uses [`nesbot/carbon`](https://carbon.nesbot.com/) as its canonical
date and time library. All date/time values are
[`Carbon\CarbonImmutable`](https://carbon.nesbot.com/docs/) instances — an
immutable point-in-time value object extending `DateTimeImmutable` with a rich
API for formatting, relative time, and arithmetic.

## Table of Contents

- [Overview](#overview)
- [Creating a CarbonImmutable](#creating-a-carbonimmutable)
- [Formatting](#formatting)
- [Relative Time](#relative-time)
- [Immutability](#immutability)

## Overview

Key features:

- Immutable `CarbonImmutable` values — safe to share and reuse
- Per-instance timezones (no global static state)
- Rich helpers for formatting, relative time, and arithmetic

## Creating a CarbonImmutable

```php
use Carbon\CarbonImmutable;

// Defaults to "now" in the local timezone.
$now = CarbonImmutable::now();

// A specific timestamp in a specific timezone.
$moment = CarbonImmutable::createFromTimestamp(1700000000, 'UTC');

// A parseable date string.
$moment = CarbonImmutable::parse('2023-11-14 22:13:20', 'UTC');

// The current time pinned to UTC — canonical for storage, logging and timestamps.
$utc = CarbonImmutable::now('UTC');
```

## Formatting

```php
$moment->format('Y-m-d H:i:s');
$moment->format('F j, Y g:i A T');      // appends the timezone abbreviation
$moment->getTimestamp();                 // Unix timestamp
$moment->toAtomString();                 // RFC 3339 / ISO 8601
$moment->toIso8601String();              // ISO 8601
```

## Relative Time

```php
$moment->diffForHumans();                // '5 minutes ago' / '5 minutes from now'
$moment->isPast();
$moment->isFuture();
```

## Immutability

`CarbonImmutable` is immutable. Every helper returns a **new** instance and
never mutates the receiver:

```php
$original = CarbonImmutable::createFromTimestamp(1700000000, 'UTC');

$added  = $original->addDay();   // new CarbonImmutable
$subbed = $original->subDay();   // new CarbonImmutable

// $original is unchanged.
```

For testing, Carbon provides `CarbonImmutable::setTestNow()` to pin the
current time deterministically.
