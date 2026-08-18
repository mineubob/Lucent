<?php

namespace Lucent\Cache;

use RuntimeException;

/**
 * Thrown when a cache driver cannot be resolved.
 *
 * Carries the driver name that was requested, so callers can see exactly
 * which driver failed to resolve.
 */
class CacheDriverException extends RuntimeException
{
    /**
     * The driver name that could not be resolved.
     */
    public private(set) string $driver;

    /**
     * @param string $driver The driver name that could not be resolved
     */
    public function __construct(string $driver)
    {
        $this->driver = $driver;
        parent::__construct(sprintf('Unable to resolve cache driver "%s".', $driver));
    }
}