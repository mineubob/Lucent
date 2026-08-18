<?php

namespace Tests\Unit\Cache;

use Lucent\Cache\Drivers\ArrayDriver;
use Lucent\Cache\InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;

class ArrayDriverTest extends TestCase
{
    public function test_implements_cache_interface(): void
    {
        $this->assertInstanceOf(CacheInterface::class, new ArrayDriver());
    }

    public function test_get_returns_default_on_miss(): void
    {
        $cache = new ArrayDriver();
        $this->assertSame('fallback', $cache->get('missing', 'fallback'));
    }

    public function test_set_and_get_round_trip(): void
    {
        $cache = new ArrayDriver();
        $cache->set('name', 'Lucent');
        $this->assertSame('Lucent', $cache->get('name'));
    }

    public function test_get_returns_null_default_when_not_specified(): void
    {
        $cache = new ArrayDriver();
        $this->assertNull($cache->get('missing'));
    }

    public function test_has_returns_true_for_stored_item(): void
    {
        $cache = new ArrayDriver();
        $cache->set('key', 'value');
        $this->assertTrue($cache->has('key'));
    }

    public function test_has_returns_false_for_missing_item(): void
    {
        $cache = new ArrayDriver();
        $this->assertFalse($cache->has('missing'));
    }

    public function test_delete_removes_item(): void
    {
        $cache = new ArrayDriver();
        $cache->set('key', 'value');
        $this->assertTrue($cache->delete('key'));
        $this->assertFalse($cache->has('key'));
    }

    public function test_delete_missing_item_returns_true(): void
    {
        $cache = new ArrayDriver();
        $this->assertTrue($cache->delete('missing'));
    }

    public function test_clear_removes_all_items(): void
    {
        $cache = new ArrayDriver();
        $cache->set('a', 1);
        $cache->set('b', 2);
        $this->assertTrue($cache->clear());
        $this->assertFalse($cache->has('a'));
        $this->assertFalse($cache->has('b'));
    }

    public function test_set_with_ttl_expires_item(): void
    {
        $cache = new ArrayDriver();
        $cache->set('key', 'value', 1);
        $this->assertTrue($cache->has('key'));
        sleep(2);
        $this->assertFalse($cache->has('key'));
        $this->assertSame('fallback', $cache->get('key', 'fallback'));
    }

    public function test_set_with_zero_ttl_does_not_store(): void
    {
        $cache = new ArrayDriver();
        $cache->set('key', 'value', 0);
        $this->assertFalse($cache->has('key'));
    }

    public function test_set_with_negative_ttl_does_not_store(): void
    {
        $cache = new ArrayDriver();
        $cache->set('key', 'value', -5);
        $this->assertFalse($cache->has('key'));
    }

    public function test_set_with_null_ttl_stores_forever(): void
    {
        $cache = new ArrayDriver();
        $cache->set('key', 'value', null);
        $this->assertTrue($cache->has('key'));
    }

    public function test_get_multiple_returns_key_value_pairs(): void
    {
        $cache = new ArrayDriver();
        $cache->set('a', 1);
        $cache->set('b', 2);

        $result = $cache->getMultiple(['a', 'b', 'c'], 'default');

        $this->assertSame(['a' => 1, 'b' => 2, 'c' => 'default'], $result);
    }

    public function test_set_multiple_stores_all_values(): void
    {
        $cache = new ArrayDriver();
        $this->assertTrue($cache->setMultiple(['a' => 1, 'b' => 2]));
        $this->assertSame(1, $cache->get('a'));
        $this->assertSame(2, $cache->get('b'));
    }

    public function test_delete_multiple_removes_all_keys(): void
    {
        $cache = new ArrayDriver();
        $cache->setMultiple(['a' => 1, 'b' => 2]);
        $this->assertTrue($cache->deleteMultiple(['a', 'b']));
        $this->assertFalse($cache->has('a'));
        $this->assertFalse($cache->has('b'));
    }

    public function test_invalid_key_throws_on_get(): void
    {
        $cache = new ArrayDriver();
        $this->expectException(InvalidArgumentException::class);
        $cache->get('invalid key');
    }

    public function test_invalid_key_throws_on_set(): void
    {
        $cache = new ArrayDriver();
        $this->expectException(InvalidArgumentException::class);
        $cache->set('invalid{key}', 'value');
    }

    public function test_invalid_key_throws_on_delete(): void
    {
        $cache = new ArrayDriver();
        $this->expectException(InvalidArgumentException::class);
        $cache->delete('invalid/key');
    }

    public function test_invalid_key_throws_on_has(): void
    {
        $cache = new ArrayDriver();
        $this->expectException(InvalidArgumentException::class);
        $cache->has('invalid@key');
    }

    public function test_empty_key_throws(): void
    {
        $cache = new ArrayDriver();
        $this->expectException(InvalidArgumentException::class);
        $cache->get('');
    }

    public function test_overlong_key_throws(): void
    {
        $cache = new ArrayDriver();
        $this->expectException(InvalidArgumentException::class);
        $cache->get(str_repeat('a', 65));
    }

    public function test_invalid_key_throws_on_get_multiple(): void
    {
        $cache = new ArrayDriver();
        $this->expectException(InvalidArgumentException::class);
        $cache->getMultiple(['valid', 'invalid key']);
    }

    public function test_invalid_key_throws_on_set_multiple(): void
    {
        $cache = new ArrayDriver();
        $this->expectException(InvalidArgumentException::class);
        $cache->setMultiple(['valid' => 1, 'invalid key' => 2]);
    }

    public function test_invalid_key_throws_on_delete_multiple(): void
    {
        $cache = new ArrayDriver();
        $this->expectException(InvalidArgumentException::class);
        $cache->deleteMultiple(['valid', 'invalid key']);
    }

    public function test_non_string_key_throws_on_get_multiple(): void
    {
        $cache = new ArrayDriver();
        $this->expectException(InvalidArgumentException::class);
        $cache->getMultiple(['valid', 42]);
    }

    public function test_non_string_key_throws_on_set_multiple(): void
    {
        $cache = new ArrayDriver();
        $this->expectException(InvalidArgumentException::class);
        $cache->setMultiple([42 => 'value']);
    }

    public function test_non_string_key_throws_on_delete_multiple(): void
    {
        $cache = new ArrayDriver();
        $this->expectException(InvalidArgumentException::class);
        $cache->deleteMultiple(['valid', 42]);
    }

    public function test_stores_arbitrary_serializable_values(): void
    {
        $cache = new ArrayDriver();
        $value = ['nested' => ['array' => true], 'int' => 42, 'float' => 1.5, 'bool' => false];
        $cache->set('complex', $value);
        $this->assertSame($value, $cache->get('complex'));
    }
}