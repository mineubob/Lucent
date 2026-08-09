<?php

namespace Lucent\Cache\Drivers;

use DateInterval;
use Lucent\Cache\Cache;

/**
 * APCu cache driver.
 *
 * Stores values in APCu's shared-memory userland cache. Fast, survives
 * across requests within a single process pool, and requires no external
 * server. Useful as a high-performance tier-2 store (e.g. query results).
 *
 * Requires the `apcu` extension. If it is not loaded, the constructor throws
 * a {@see \RuntimeException} rather than failing silently at runtime.
 */
class ApcuDriver extends Cache
{
    /**
     * @throws \RuntimeException If the `apcu` extension is not loaded
     */
    public function __construct()
    {
        if (!extension_loaded('apcu')) {
            throw new \RuntimeException(
                'The APCu cache driver requires the "apcu" extension. Install it or select a different CACHE_DRIVER.'
            );
        }
    }

    /**
     * @inheritDoc
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $this->validateKey($key);

        $success = false;
        $value = \apcu_fetch($key, $success);

        return $success ? $value : $default;
    }

    /**
     * @inheritDoc
     */
    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        $this->validateKey($key);

        $expires = $this->expiryFromTtl($this->resolveTtl($ttl));

        if ($expires !== null && $expires <= time()) {
            return $this->delete($key);
        }

        // APCu's TTL is a lifetime in seconds from now, not an absolute
        // expiry timestamp. Convert the absolute expiry back to a duration.
        $lifetime = $expires === null ? 0 : max(0, $expires - time());

        return \apcu_store($key, $value, $lifetime);
    }

    /**
     * @inheritDoc
     */
    public function delete(string $key): bool
    {
        $this->validateKey($key);

        return \apcu_delete($key);
    }

    /**
     * @inheritDoc
     */
    public function clear(): bool
    {
        return \apcu_clear_cache();
    }

    /**
     * @inheritDoc
     */
    public function has(string $key): bool
    {
        $this->validateKey($key);

        return \apcu_exists($key);
    }
}
