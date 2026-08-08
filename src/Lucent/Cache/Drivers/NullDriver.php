<?php

namespace Lucent\Cache\Drivers;

use DateInterval;
use Lucent\Cache\Cache;

/**
 * Null cache driver.
 *
 * Every read is a miss and every write is a no-op. Useful for disabling
 * caching entirely (e.g. in local development) while keeping the same
 * interface.
 */
class NullDriver extends Cache
{
    /**
     * @inheritDoc
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $this->validateKey($key);

        return $default;
    }

    /**
     * @inheritDoc
     */
    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        $this->validateKey($key);

        return true;
    }

    /**
     * @inheritDoc
     */
    public function delete(string $key): bool
    {
        $this->validateKey($key);

        return true;
    }

    /**
     * @inheritDoc
     */
    public function clear(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function has(string $key): bool
    {
        $this->validateKey($key);

        return false;
    }
}