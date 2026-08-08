<?php

namespace Lucent\Cache\Drivers;

use DateInterval;
use Lucent\Cache\Cache;

/**
 * In-memory cache driver.
 *
 * Stores values in a plain array for the lifetime of the process. Useful
 * for tests and for short-lived request-scoped caching where persistence
 * across requests is not required.
 */
class ArrayDriver extends Cache
{
    /**
     * Stored values keyed by cache key.
     *
     * @var array<string, array{expires: int|null, value: mixed}>
     */
    private array $store = [];

    /**
     * @inheritDoc
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $this->validateKey($key);

        if (!array_key_exists($key, $this->store)) {
            return $default;
        }

        $entry = $this->store[$key];

        if ($entry['expires'] !== null && $entry['expires'] <= time()) {
            unset($this->store[$key]);
            return $default;
        }

        return $entry['value'];
    }

    /**
     * @inheritDoc
     */
    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        $this->validateKey($key);

        $expires = $this->expiryFromTtl($ttl);

        if ($expires !== null && $expires <= time()) {
            unset($this->store[$key]);
            return true;
        }

        $this->store[$key] = ['expires' => $expires, 'value' => $value];

        return true;
    }

    /**
     * @inheritDoc
     */
    public function delete(string $key): bool
    {
        $this->validateKey($key);

        unset($this->store[$key]);

        return true;
    }

    /**
     * @inheritDoc
     */
    public function clear(): bool
    {
        $this->store = [];

        return true;
    }

    /**
     * @inheritDoc
     */
    public function has(string $key): bool
    {
        $this->validateKey($key);

        if (!array_key_exists($key, $this->store)) {
            return false;
        }

        $entry = $this->store[$key];

        if ($entry['expires'] !== null && $entry['expires'] <= time()) {
            unset($this->store[$key]);
            return false;
        }

        return true;
    }
}