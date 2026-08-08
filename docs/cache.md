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
| `array` | In-memory store, cleared when the process ends (useful for tests)           |
| `null`  | Every read is a miss and every write is a no-op (disables caching)          |

Any other driver name is resolved from the application container, so you can
register your own driver class and select it via `CACHE_DRIVER`.

## Configuration

| Variable             | Default          | Description                                        |
|----------------------|------------------|----------------------------------------------------|
| `CACHE_DRIVER`       | `file`           | The driver to use (`file`, `array`, `null`, or a container identifier) |
| `CACHE_PATH`         | `storage/cache`  | Directory used by the `file` driver (relative to root) |
| `CACHE_DEFAULT_TTL`  | *(none)*         | Optional default TTL in seconds when none is given |

## Usage

### Via the Cache Facade

```php
use Lucent\Facades\Cache;

// Store a value for 60 seconds.
Cache::set('user:42', $user, 60);

// Store a value with no expiry.
Cache::set('config', $config);

// Fetch a value, with a default on a miss.
$user = Cache::get('user:42', $fallbackUser);

// Check, delete, and clear.
Cache::has('user:42');
Cache::delete('user:42');
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