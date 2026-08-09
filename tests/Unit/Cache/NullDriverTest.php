<?php

namespace Tests\Unit\Cache;

use Lucent\Cache\Drivers\NullDriver;
use Lucent\Cache\InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;

class NullDriverTest extends TestCase
{
    public function test_implements_cache_interface(): void
    {
        $this->assertInstanceOf(CacheInterface::class, new NullDriver());
    }

    public function test_get_always_returns_default(): void
    {
        $cache = new NullDriver();
        $this->assertSame('fallback', $cache->get('any', 'fallback'));
    }

    public function test_get_returns_null_default_when_not_specified(): void
    {
        $cache = new NullDriver();
        $this->assertNull($cache->get('any'));
    }

    public function test_has_always_returns_false(): void
    {
        $cache = new NullDriver();
        $cache->set('key', 'value');
        $this->assertFalse($cache->has('key'));
    }

    public function test_set_is_noop_but_returns_true(): void
    {
        $cache = new NullDriver();
        $this->assertTrue($cache->set('key', 'value'));
    }

    public function test_delete_returns_true(): void
    {
        $cache = new NullDriver();
        $this->assertTrue($cache->delete('key'));
    }

    public function test_clear_returns_true(): void
    {
        $cache = new NullDriver();
        $this->assertTrue($cache->clear());
    }

    public function test_get_multiple_returns_defaults(): void
    {
        $cache = new NullDriver();
        $this->assertSame(
            ['a' => 'default', 'b' => 'default'],
            $cache->getMultiple(['a', 'b'], 'default')
        );
    }

    public function test_set_multiple_returns_true(): void
    {
        $cache = new NullDriver();
        $this->assertTrue($cache->setMultiple(['a' => 1, 'b' => 2]));
    }

    public function test_delete_multiple_returns_true(): void
    {
        $cache = new NullDriver();
        $this->assertTrue($cache->deleteMultiple(['a', 'b']));
    }

    public function test_invalid_key_throws_on_get(): void
    {
        $cache = new NullDriver();
        $this->expectException(InvalidArgumentException::class);
        $cache->get('invalid key');
    }

    public function test_invalid_key_throws_on_set(): void
    {
        $cache = new NullDriver();
        $this->expectException(InvalidArgumentException::class);
        $cache->set('invalid{key}', 'value');
    }

    public function test_invalid_key_throws_on_has(): void
    {
        $cache = new NullDriver();
        $this->expectException(InvalidArgumentException::class);
        $cache->has('invalid@key');
    }
}