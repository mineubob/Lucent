[Home](../README.md)

# Caching

Lucent ships with a [PSR-16](https://www.php-fig.org/psr/psr-16/) compliant
simple cache, providing a clean, consistent interface for storing and
retrieving values. The cache store is owned by the application singleton and
can be swapped for any compatible implementation.

## Drivers

The cache is backed by a driver selected via the `CACHE_DRIVER` environment
variable:

| Driver  | Description                                                                 |
|---------|-----------------------------------------------------------------------------|
| `file`  | Persists values to disk under `storage/cache` (default)                     |
| `apcu`  | Shared-memory store via the `apcu` extension (fast, no external server)     |
| `array` | In-memory store, cleared when the process ends (useful for tests)           |
| `null`  | Every read is a miss and every write is a no-op (disables caching)          |

> **Note:** the `file` driver hashes each key with SHA-256 and stores the
> value at a sharded path such as `storage/cache/ab/cd/<sha256>.cache`. The
> hash and sharding are internal — keys are always passed to and returned
> from the cache interface unmodified. Don't rely on the on-disk filenames;
> use the cache API to read and write values.

Any other driver name is resolved from the application container, so you can
register your own driver class and select it via `CACHE_DRIVER`.

> **Note:** the `apcu` driver requires the `apcu` extension. If it is not
> loaded, selecting it throws a `CacheDriverException` rather than failing
> silently at runtime.

## Configuration

| Variable             | Default          | Description                                        |
|----------------------|------------------|----------------------------------------------------|
| `CACHE_DRIVER`       | `file`           | The driver to use (`file`, `apcu`, `array`, `null`, or a container identifier) |
| `CACHE_PATH`         | `storage/cache`  | Directory used by the `file` driver (relative to root) |
| `CACHE_DEFAULT_TTL`  | *(none)*         | Optional default TTL in seconds applied when a `set()` call omits one |

## Keys

Cache keys must be non-empty strings of at most 512 characters drawn from
`[A-Za-z0-9_.]`. The reserved characters `{}()/\@:` are rejected and throw a
`Lucent\Cache\InvalidArgumentException`.

These rules follow [PSR-16](https://www.php-fig.org/psr/psr-16/): the spec
requires supporting at least 64 characters and the `[A-Za-z0-9_.]` character
set; Lucent supports up to 512 characters. Keys are returned to the caller
unmodified — any internal transformation (such as hashing for the `file`
driver) is invisible through the cache interface.

## Query Cache

Lucent ships with an opt-in query cache for model collections. When a query
cache store is injected, `Database::select()` caches raw result rows and
re-hydrates them on subsequent identical queries, avoiding repeated database
queries.

The query cache is **off by default** — no store is injected until you enable
it. It uses its **own** dedicated store, separate from the main cache, so each
can use a different driver.

### Enabling

The application auto-injects its dedicated query cache store into `Database`
when the `QUERY_CACHE` environment variable is truthy:

```dotenv
QUERY_CACHE=true
```

The query cache store is built from its own environment variables:

| Variable               | Default          | Description                                        |
|------------------------|------------------|----------------------------------------------------|
| `QUERY_CACHE`          | `false`          | Master on/off switch for the query cache            |
| `QUERY_CACHE_DRIVER`   | `array`          | Driver for the query cache store                    |
| `QUERY_CACHE_PATH`     | `storage/cache`  | Directory used by the `file` query cache driver     |

When `QUERY_CACHE` is truthy, `Application::queryCache()` builds the store and
passes it to `Database::setQueryCache()`, so SELECT results are cached. When
`QUERY_CACHE` is falsy (or unset), no query cache is injected and queries run
directly.

You can also inject a store manually via `Database::setQueryCache()`:

```php
use Lucent\Cache\Drivers\ArrayDriver;
use Lucent\Database;

Database::setQueryCache(new ArrayDriver());
```

Pass `null` to disable query caching again:

```php
Database::setQueryCache(null);
```

The store is owned by the application and injected into `Database`, so the ORM
never constructs a cache driver itself.

> **Note:** the query cache has no invalidation on model writes. Cached
> results may be stale until the TTL expires, so only enable it when that
> trade-off is acceptable.

## Usage

### Via the Cache Facade

```php
use Lucent\Facades\Cache;

// Store a value for 60 seconds.
Cache::set('user.42', $user, 60);

// Store a value with no expiry.
Cache::set('config', $config);

// Fetch a value, with a default on a miss.
$user = Cache::get('user.42', $fallbackUser);

// Check, delete, and clear.
Cache::has('user.42');
Cache::delete('user.42');
Cache::clear();
```

### Via Dependency Injection

The store is registered on the container under
`Psr\SimpleCache\CacheInterface`, so it can be injected into your classes:

```php
use Psr\SimpleCache\CacheInterface;

class UserService
{
    public function __construct(private CacheInterface $cache) {}
}
```

### Swapping in a Custom Implementation

Because the store is injected as `CacheInterface`, you can replace it with any
compatible implementation — including third-party libraries:

```php
use Lucent\Application;
use Psr\SimpleCache\CacheInterface;

Application::getInstance()->setCache(new MyCacheImplementation());
```

Alternatively, register your own driver class in the container and select it
via `CACHE_DRIVER`:

```php
use Lucent\Facades\App;

App::container()->singleton(MyRedisCache::class);
```

```dotenv
CACHE_DRIVER=MyRedisCache
```

## TTL Semantics

- `null` TTL caches the value forever (or until the driver's limit).
- A positive integer is a number of seconds from now.
- A `DateInterval` is a duration from now.
- A zero or negative TTL means the item is already expired and is removed
  rather than stored.

## Clearing the Cache

The `cache:clear` command wipes the entire cache store:

```bash
php cli cache:clear
```