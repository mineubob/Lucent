<?php
declare(strict_types=1);


namespace Lucent\Facades;

use DateInterval;
use Lucent\Application;
use Psr\SimpleCache\CacheInterface;

/**
 * Cache facade.
 *
 * Provides static access to the application's cache store. The store is
 * owned by the {@see Application} singleton, so this facade simply delegates
 * to {@see Application::cache()}.
 */
class Cache
{
    /**
     * Get the application's cache store.
     *
     * @return CacheInterface The cache store
     */
    public static function store(): CacheInterface
    {
        return Application::getInstance()->cache();
    }

    /**
     * Fetch a value from the cache.
     *
     * @param string $key The cache key
     * @param mixed $default Default value to return on a miss
     * @return mixed The cached value, or $default on a miss
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return self::store()->get($key, $default);
    }

    /**
     * Persist a value in the cache.
     *
     * @param string $key The cache key
     * @param mixed $value The value to store
     * @param null|int|DateInterval $ttl Optional TTL
     * @return bool True on success, false on failure
     */
    public static function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        return self::store()->set($key, $value, $ttl);
    }

    /**
     * Delete an item from the cache.
     *
     * @param string $key The cache key
     * @return bool True on success, false on failure
     */
    public static function delete(string $key): bool
    {
        return self::store()->delete($key);
    }

    /**
     * Wipe the entire cache.
     *
     * @return bool True on success, false on failure
     */
    public static function clear(): bool
    {
        return self::store()->clear();
    }

    /**
     * Fetch multiple values from the cache.
     *
     * @param iterable<string> $keys The cache keys
     * @param mixed $default Default value for keys that are missing
     * @return iterable<string, mixed> Key => value pairs
     */
    public static function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        return self::store()->getMultiple($keys, $default);
    }

    /**
     * Persist multiple values in the cache.
     *
     * @param iterable $values Key => value pairs to store
     * @param null|int|DateInterval $ttl Optional TTL
     * @return bool True on success, false on failure
     */
    public static function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        return self::store()->setMultiple($values, $ttl);
    }

    /**
     * Delete multiple items from the cache.
     *
     * @param iterable<string> $keys The cache keys
     * @return bool True on success, false on failure
     */
    public static function deleteMultiple(iterable $keys): bool
    {
        return self::store()->deleteMultiple($keys);
    }

    /**
     * Determine whether an item is present in the cache.
     *
     * @param string $key The cache key
     * @return bool True if the item exists and is not expired
     */
    public static function has(string $key): bool
    {
        return self::store()->has($key);
    }
}