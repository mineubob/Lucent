<?php

namespace Tests\Unit\Cache;

use Lucent\Application;
use Lucent\Cache\CacheDriverException;
use Lucent\Cache\CacheFactory;
use Lucent\Cache\Drivers\ApcuDriver;
use Lucent\Cache\Drivers\ArrayDriver;
use Lucent\Cache\Drivers\FileDriver;
use Lucent\Cache\Drivers\NullDriver;
use Lucent\Facades\Cache;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use Tests\Support\Concerns\RefreshApplication;

class CacheTest extends TestCase
{
    use RefreshApplication;

    protected function setUp(): void
    {
        parent::setUp();
        self::refreshApplication();
    }

    public function test_application_cache_returns_shared_instance(): void
    {
        $app = Application::getInstance();

        $this->assertSame($app->cache(), $app->cache());
    }

    public function test_application_cache_defaults_to_file_driver(): void
    {
        $app = Application::getInstance();

        $this->assertInstanceOf(FileDriver::class, $app->cache());
    }

    public function test_application_cache_respects_cache_driver_env(): void
    {
        $app = Application::getInstance();
        $app->setEnv(['CACHE_DRIVER' => 'array']);

        $this->assertInstanceOf(ArrayDriver::class, $app->cache());
    }

    public function test_application_cache_respects_null_driver_env(): void
    {
        $app = Application::getInstance();
        $app->setEnv(['CACHE_DRIVER' => 'null']);

        $this->assertInstanceOf(NullDriver::class, $app->cache());
    }

    public function test_application_cache_registers_on_container(): void
    {
        $app = Application::getInstance();

        $this->assertSame(
            $app->cache(),
            $app->container()->get(CacheInterface::class)
        );
    }

    public function test_application_cache_applies_default_ttl(): void
    {
        $app = Application::getInstance();
        $app->setEnv(['CACHE_DRIVER' => 'array', 'CACHE_DEFAULT_TTL' => '30']);

        $store = $app->cache();

        $this->assertInstanceOf(ArrayDriver::class, $store);
        $this->assertSame(30, $store->getDefaultTtl());
    }

    public function test_set_cache_replaces_store_and_container_binding(): void
    {
        $app = Application::getInstance();
        $replacement = new ArrayDriver();

        $app->setCache($replacement);

        $this->assertSame($replacement, $app->cache());
        $this->assertSame($replacement, $app->container()->get(CacheInterface::class));
    }

    public function test_set_cache_accepts_third_party_implementation(): void
    {
        $app = Application::getInstance();
        $custom = new CustomCacheStub();

        $app->setCache($custom);

        $this->assertSame($custom, $app->cache());
        $this->assertSame($custom, $app->container()->get(CacheInterface::class));
    }

    public function test_facade_delegates_to_application_store(): void
    {
        $app = Application::getInstance();

        $this->assertSame($app->cache(), Cache::store());
    }

    public function test_facade_get_set_round_trip(): void
    {
        Cache::set('key', 'value');
        $this->assertSame('value', Cache::get('key'));
    }

    public function test_facade_has_delete_clear(): void
    {
        Cache::set('key', 'value');
        $this->assertTrue(Cache::has('key'));
        $this->assertTrue(Cache::delete('key'));
        $this->assertFalse(Cache::has('key'));

        Cache::set('a', 1);
        Cache::set('b', 2);
        $this->assertTrue(Cache::clear());
        $this->assertFalse(Cache::has('a'));
        $this->assertFalse(Cache::has('b'));
    }

    public function test_factory_creates_builtin_drivers(): void
    {
        $container = Application::getInstance()->container();

        $this->assertInstanceOf(ArrayDriver::class, CacheFactory::create('array', $container));
        $this->assertInstanceOf(FileDriver::class, CacheFactory::create('file', $container));
        $this->assertInstanceOf(NullDriver::class, CacheFactory::create('null', $container));
    }

    public function test_factory_creates_apcu_driver_when_extension_loaded(): void
    {
        if (!extension_loaded('apcu')) {
            $this->markTestSkipped('The apcu extension is not loaded.');
        }

        $container = Application::getInstance()->container();

        $this->assertInstanceOf(ApcuDriver::class, CacheFactory::create('apcu', $container));
    }

    public function test_factory_throws_when_apcu_extension_missing(): void
    {
        if (extension_loaded('apcu')) {
            $this->markTestSkipped('The apcu extension is loaded, so the missing-extension path cannot be tested.');
        }

        $container = Application::getInstance()->container();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('requires the "apcu" extension');
        CacheFactory::create('apcu', $container);
    }

    public function test_factory_resolves_custom_driver_from_container(): void
    {
        $container = Application::getInstance()->container();
        $custom = new CustomCacheStub();
        $container->instance(CustomCacheStub::class, $custom);

        $this->assertSame($custom, CacheFactory::create(CustomCacheStub::class, $container));
    }

    public function test_factory_throws_for_unknown_driver(): void
    {
        $container = Application::getInstance()->container();

        $this->expectException(CacheDriverException::class);
        $this->expectExceptionMessage('Unable to resolve cache driver');
        CacheFactory::create('unknown-driver', $container);
    }

    public function test_factory_throws_when_resolved_value_is_not_a_driver(): void
    {
        $container = Application::getInstance()->container();
        $container->instance('not-a-driver', new \stdClass());

        $this->expectException(CacheDriverException::class);
        $this->expectExceptionMessage('Unable to resolve cache driver');
        CacheFactory::create('not-a-driver', $container);
    }
}

/**
 * A minimal third-party cache implementation used to prove that any
 * compatible implementation can be injected.
 */
class CustomCacheStub implements CacheInterface
{
    private array $store = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->store[$key] ?? $default;
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $this->store[$key] = $value;
        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->store[$key]);
        return true;
    }

    public function clear(): bool
    {
        $this->store = [];
        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }
        return $result;
    }

    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value, $ttl);
        }
        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }
        return true;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->store);
    }
}