<?php
declare(strict_types=1);


namespace Lucent\Cache;

use Psr\SimpleCache\InvalidArgumentException as SimpleCacheInvalidArgumentException;
use InvalidArgumentException as BaseInvalidArgumentException;

/**
 * Thrown when a cache key (or an iterable of keys) is not a legal value.
 *
 * Carries the offending key so callers can see exactly which value failed
 * validation.
 */
class InvalidArgumentException extends BaseInvalidArgumentException implements SimpleCacheInvalidArgumentException
{
    /**
     * The key that failed validation.
     */
    public private(set) mixed $key;

    /**
     * @param mixed $key The key that failed validation
     */
    public function __construct(mixed $key)
    {
        $this->key = $key;
        parent::__construct(sprintf('Invalid cache key "%s".', is_scalar($key) ? $key : get_debug_type($key)));
    }
}