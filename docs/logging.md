[Home](../README.md)

# Logging

Lucent's logging system is built around **channels** and **drivers**, and is fully compliant with the [PSR-3](https://www.php-fig.org/psr/psr-3/) logging interface. Any code that expects a `Psr\Log\LoggerInterface` can use a Lucent channel directly.

## Quick Start

The `Log` facade is the primary entry point. It returns a channel by name:

```php
use Lucent\Facades\Log;

Log::channel('lucent.routing')->info('Route matched: {route}', ['route' => '/users']);
Log::channel('lucent.db')->error('Query failed: {query}', ['query' => $sql]);
```

If the requested channel has not been registered, a silent `NullChannel` is returned — logging calls become no-ops instead of throwing.

## PSR-3 Compliance

Every channel implements `Psr\Log\LoggerInterface`, so it supports all eight log levels plus the generic `log()` method:

| Level | Method |
|-------|--------|
| Emergency | `emergency($message, $context = [])` |
| Alert | `alert($message, $context = [])` |
| Critical | `critical($message, $context = [])` |
| Error | `error($message, $context = [])` |
| Warning | `warning($message, $context = [])` |
| Notice | `notice($message, $context = [])` |
| Info | `info($message, $context = [])` |
| Debug | `debug($message, $context = [])` |
| Any level | `log($level, $message, $context = [])` |

`$message` may be a string or any object implementing `__toString()` (`\Stringable`). Calling `log()` with an unknown level throws `Psr\Log\InvalidArgumentException`.

### Context Interpolation

Messages may contain `{placeholder}` tokens that are replaced with values from the `$context` array:

```php
Log::channel('lucent.http')->info('Downloaded {bytes} bytes from {url}', [
    'bytes' => 1048576,
    'url'   => 'https://example.com/file.zip',
]);
// [2026-08-04 12:00:00] INFO | lucent.http | Downloaded 1048576 bytes from https://example.com/file.zip
```

Rules:

- Only string, scalar, and `\Stringable` context values are interpolated.
- Missing keys leave the placeholder untouched.
- The `exception` key is never interpolated — pass exceptions in context for stack-trace tooling, not for inlining.

## Channels and Drivers

A **channel** is a named logger. A **driver** decides where the formatted line goes.

| Driver | Destination |
|--------|-------------|
| `CliDriver` | `STDERR` (CLI only) |
| `FileDriver` | A file under `{project_root}/logs/` |
| `NullDriver` | Discards everything |
| `TeeDriver` | Writes to two drivers at once |

### Registering a Custom Channel

```php
use Lucent\Application;
use Lucent\Logging\Channel;
use Lucent\Logging\Drivers\FileDriver;
use Lucent\Logging\Drivers\CliDriver;
use Lucent\Logging\Drivers\TeeDriver;

$app = Application::getInstance();

// Single driver — the channel is registered under its own name ('audit')
$app->addLoggingChannel(new Channel('audit', new FileDriver('/var/log/audit.log')));

// Composite driver — write to both a file and STDERR
$app->addLoggingChannel(new Channel('audit', new TeeDriver(
    new FileDriver('/var/log/audit.log'),
    new CliDriver(),
)));

// Override the registry key (useful for aliasing) — the channel still
// prints its own name ('audit') in log output, but is looked up as 'audit2'
$app->addLoggingChannel(new Channel('audit', new FileDriver('/var/log/audit.log')), 'audit2');
```

### Custom Drivers

A driver is any class extending `Lucent\Logging\Driver` with a single `write(string $line): void` method:

```php
use Lucent\Logging\Driver;

class SyslogDriver extends Driver
{
    public function write(string $line): void
    {
        syslog(LOG_INFO, trim($line));
    }
}
```

## Database Logging

The database layer accepts any PSR-3 logger via `Database::setLogger()`:

```php
use Lucent\Database;
use Lucent\Logging\Channel;
use Lucent\Logging\Drivers\FileDriver;

Database::setLogger(new Channel('lucent.db', new FileDriver('/var/log/db.log')));
```

`Database::log($level, $message, $context = [])` routes through the configured logger. If no logger is set, calls are silently dropped (mirroring the PSR-3 `NullLogger` convention).

> **Note:** The legacy `Lucent\Database\DatabaseLogger` interface is deprecated. It is kept for backward compatibility but `Database::setLogger()` now accepts `Psr\Log\LoggerInterface` directly.

## Built-in Channels

The framework registers these channels during boot:

| Channel | Purpose |
|---------|---------|
| `lucent.db` | Database queries and errors |
| `lucent.routing` | Route matching and HTTP errors |
| `lucent.filesystem` | File operations |
| `lucent.http` | HTTP client downloads and requests |
| `lucent.commandline` | CLI command output |