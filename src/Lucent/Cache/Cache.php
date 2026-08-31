<?php
declare(strict_types=1);


namespace Lucent\Cache;

use DateInterval;
use Psr\SimpleCache\CacheInterface;

/**
 * Shared foundation for cache drivers.
 *
 * Provides key validation and TTL normalisation, plus default loop
 * implementations for the multi-key operations built on top of the
 * single-key methods each driver implements.
 *
 * Keys must be non-empty strings of at most 512 characters drawn from
 * `[A-Za-z0-9_.]`. The reserved characters `{}()/\@:` are rejected.
 *
 * TTL semantics:
 *  - `null` means "cache forever" (no expiry).
 *  - a positive integer is a number of seconds from now.
 *  - a {@see DateInterval} is a duration from now.
 *  - zero or a negative value means the item is already expired and must
 *    be removed rather than stored.
 */
abstract class Cache implements CacheInterface
{
    /**
     * Default TTL in seconds applied when a {@see set()} call omits one.
     *
     * `null` means "no default" — an omitted TTL caches forever, matching the
     * PSR-16 semantics. Set via {@see setDefaultTtl()}.
     *
     * @var int|null
     */
    private ?int $defaultTtl = null;

    /**
     * Set the default TTL applied when a {@see set()} call omits one.
     *
     * @param int|null $ttl Default TTL in seconds, or null for no default
     * @return void
     */
    public function setDefaultTtl(?int $ttl): void
    {
        $this->defaultTtl = $ttl;
    }

    /**
     * Get the configured default TTL.
     *
     * @return int|null Default TTL in seconds, or null when none is set
     */
    public function getDefaultTtl(): ?int
    {
        return $this->defaultTtl;
    }

    /**
     * Resolve the effective TTL for a {@see set()} call.
     *
     * Returns the caller-supplied TTL, or the configured default when none
     * was given.
     *
     * @param null|int|DateInterval $ttl The caller-supplied TTL
     * @return null|int|DateInterval The effective TTL
     */
    protected function resolveTtl(null|int|DateInterval $ttl): null|int|DateInterval
    {
        return $ttl ?? $this->defaultTtl;
    }

    /**
     * Validate a cache key.
     *
     * Keys must be strings; anything else is rejected so callers get a
     * consistent {@see InvalidArgumentException} rather than a TypeError.
     *
     * The 512-character cap is well above the PSR-16 minimum of 64 and is
     * bounded to keep cache metadata from being abused. The character set
     * `[A-Za-z0-9_.]` matches the PSR-16 required set; the reserved
     * characters `{}()/\@:` are rejected.
     *
     * @param mixed $key The key to validate
     * @return void
     * @throws InvalidArgumentException If the key is not a legal value
     */
    protected function validateKey(mixed $key): void
    {
        if (!is_string($key) || preg_match('/^[A-Za-z0-9_.]{1,512}$/', $key) !== 1) {
            throw new InvalidArgumentException($key);
        }
    }

    /**
     * Convert a TTL into an absolute expiry timestamp.
     *
     * @param null|int|DateInterval $ttl The TTL to convert
     * @return int|null Absolute unix timestamp of expiry, or null for "forever"
     */
    protected function expiryFromTtl(null|int|DateInterval $ttl): ?int
    {
        if ($ttl === null) {
            return null;
        }

        if ($ttl instanceof DateInterval) {
            $ttl = (new \DateTimeImmutable())->add($ttl)->getTimestamp() - time();
        }

        if ($ttl <= 0) {
            // Already expired: return a timestamp in the past so the item is
            // treated as stale and removed rather than stored.
            return time() - 1;
        }

        return time() + $ttl;
    }

    /**
     * @inheritDoc
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];

        foreach ($keys as $key) {
            $this->validateKey($key);
            $result[$key] = $this->get($key, $default);
        }

        return $result;
    }

    /**
     * @inheritDoc
     */
    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        $success = true;

        foreach ($values as $key => $value) {
            $this->validateKey($key);
            if (!$this->set($key, $value, $ttl)) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * @inheritDoc
     */
    public function deleteMultiple(iterable $keys): bool
    {
        $success = true;

        foreach ($keys as $key) {
            $this->validateKey($key);
            if (!$this->delete($key)) {
                $success = false;
            }
        }

        return $success;
    }
}