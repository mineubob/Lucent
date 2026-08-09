<?php

namespace Lucent\Cache;

use Lucent\Cache\Drivers\ApcuDriver;
use Lucent\Cache\Drivers\ArrayDriver;
use Lucent\Cache\Drivers\FileDriver;
use Lucent\Cache\Drivers\NullDriver;
use Lucent\Container\Container;
use Psr\SimpleCache\CacheInterface;

/**
 * Builds cache driver instances from a driver name.
 *
 * The built-in drivers are `array`, `file` and `null`. Any other name is
 * resolved from the application container, so applications can register
 * their own driver class and select it via the `CACHE_DRIVER` environment
 * variable.
 */
class CacheFactory
{
    /**
     * Create a cache driver by name.
     *
     * @param string $name Driver name (`array`, `file`, `null`, or a container identifier)
     * @param Container $container The application container, used to resolve custom drivers
     * @param string $path Cache directory for the file driver (relative to root)
     * @return CacheInterface The resolved driver
     * @throws CacheDriverException If the driver name is unknown and cannot be resolved
     */
    public static function create(string $name, Container $container, string $path = 'storage/cache'): CacheInterface
    {
        return match ($name) {
            'array' => new ArrayDriver(),
            'file' => new FileDriver($path),
            'null' => new NullDriver(),
            'apcu' => new ApcuDriver(),
            default => self::resolveCustom($name, $container),
        };
    }

    /**
     * Resolve a custom driver from the container.
     *
     * @param string $name Container identifier for the driver
     * @param Container $container The application container
     * @return CacheInterface The resolved driver
     * @throws CacheDriverException If the container cannot resolve the name, or the result is not a cache driver
     */
    private static function resolveCustom(string $name, Container $container): CacheInterface
    {
        if (!$container->has($name)) {
            throw new CacheDriverException($name);
        }

        $driver = $container->get($name);

        if (!$driver instanceof CacheInterface) {
            throw new CacheDriverException($name);
        }

        return $driver;
    }
}