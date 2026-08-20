<?php

namespace Tests\Unit\Cache;

use Lucent\Cache\Drivers\FileDriver;
use Lucent\Cache\InvalidArgumentException;
use Lucent\Facades\FileSystem;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;

class FileDriverTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheDir = FileSystem::rootPath() . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache';

        // Ensure a clean cache directory for each test.
        if (is_dir($this->cacheDir)) {
            foreach (glob($this->cacheDir . DIRECTORY_SEPARATOR . '*.cache') ?: [] as $file) {
                unlink($file);
            }
        }
    }

    public function test_implements_cache_interface(): void
    {
        $this->assertInstanceOf(CacheInterface::class, new FileDriver());
    }

    public function test_get_returns_default_on_miss(): void
    {
        $cache = new FileDriver();
        $this->assertSame('fallback', $cache->get('missing', 'fallback'));
    }

    public function test_set_and_get_round_trip(): void
    {
        $cache = new FileDriver();
        $cache->set('name', 'Lucent');
        $this->assertSame('Lucent', $cache->get('name'));
    }

    public function test_set_creates_cache_directory(): void
    {
        $cache = new FileDriver();
        $cache->set('key', 'value');
        $this->assertDirectoryExists($this->cacheDir);
    }

    public function test_uses_key_directly_as_filename(): void
    {
        $cache = new FileDriver();
        $cache->set('my.key', 'value');

        $this->assertFileExists($this->cacheDir . DIRECTORY_SEPARATOR . 'my.key.cache');
    }

    public function test_accepts_absolute_cache_directory(): void
    {
        $absolute = FileSystem::rootPath() . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache-absolute';
        $cache = new FileDriver($absolute);
        $cache->set('key', 'value');

        $this->assertFileExists($absolute . DIRECTORY_SEPARATOR . 'key.cache');
    }

    public function test_has_returns_true_for_stored_item(): void
    {
        $cache = new FileDriver();
        $cache->set('key', 'value');
        $this->assertTrue($cache->has('key'));
    }

    public function test_has_returns_false_for_missing_item(): void
    {
        $cache = new FileDriver();
        $this->assertFalse($cache->has('missing'));
    }

    public function test_delete_removes_item(): void
    {
        $cache = new FileDriver();
        $cache->set('key', 'value');
        $this->assertTrue($cache->delete('key'));
        $this->assertFalse($cache->has('key'));
    }

    public function test_delete_missing_item_returns_true(): void
    {
        $cache = new FileDriver();
        $this->assertTrue($cache->delete('missing'));
    }

    public function test_clear_removes_all_items(): void
    {
        $cache = new FileDriver();
        $cache->set('a', 1);
        $cache->set('b', 2);
        $this->assertTrue($cache->clear());
        $this->assertFalse($cache->has('a'));
        $this->assertFalse($cache->has('b'));
    }

    public function test_set_with_ttl_expires_item(): void
    {
        $cache = new FileDriver();
        $cache->set('key', 'value', 1);
        $this->assertTrue($cache->has('key'));
        sleep(2);
        $this->assertFalse($cache->has('key'));
        $this->assertSame('fallback', $cache->get('key', 'fallback'));
    }

    public function test_set_with_zero_ttl_does_not_store(): void
    {
        $cache = new FileDriver();
        $cache->set('key', 'value', 0);
        $this->assertFalse($cache->has('key'));
    }

    public function test_set_with_negative_ttl_does_not_store(): void
    {
        $cache = new FileDriver();
        $cache->set('key', 'value', -5);
        $this->assertFalse($cache->has('key'));
    }

    public function test_set_with_null_ttl_stores_forever(): void
    {
        $cache = new FileDriver();
        $cache->set('key', 'value', null);
        $this->assertTrue($cache->has('key'));
    }

    public function test_persists_across_instances(): void
    {
        $first = new FileDriver();
        $first->set('key', 'persisted');

        $second = new FileDriver();
        $this->assertSame('persisted', $second->get('key'));
    }

    public function test_get_multiple_returns_key_value_pairs(): void
    {
        $cache = new FileDriver();
        $cache->set('a', 1);
        $cache->set('b', 2);

        $result = $cache->getMultiple(['a', 'b', 'c'], 'default');

        $this->assertSame(['a' => 1, 'b' => 2, 'c' => 'default'], $result);
    }

    public function test_set_multiple_stores_all_values(): void
    {
        $cache = new FileDriver();
        $this->assertTrue($cache->setMultiple(['a' => 1, 'b' => 2]));
        $this->assertSame(1, $cache->get('a'));
        $this->assertSame(2, $cache->get('b'));
    }

    public function test_delete_multiple_removes_all_keys(): void
    {
        $cache = new FileDriver();
        $cache->setMultiple(['a' => 1, 'b' => 2]);
        $this->assertTrue($cache->deleteMultiple(['a', 'b']));
        $this->assertFalse($cache->has('a'));
        $this->assertFalse($cache->has('b'));
    }

    public function test_stores_arbitrary_serializable_values(): void
    {
        $cache = new FileDriver();
        $value = ['nested' => ['array' => true], 'int' => 42, 'float' => 1.5, 'bool' => false];
        $cache->set('complex', $value);
        $this->assertSame($value, $cache->get('complex'));
    }

    public function test_invalid_key_throws_on_get(): void
    {
        $cache = new FileDriver();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid key');
        $cache->get('invalid key');
    }

    public function test_invalid_key_throws_on_set(): void
    {
        $cache = new FileDriver();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid{key}');
        $cache->set('invalid{key}', 'value');
    }

    public function test_invalid_key_throws_on_delete(): void
    {
        $cache = new FileDriver();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid/key');
        $cache->delete('invalid/key');
    }

    public function test_invalid_key_throws_on_has(): void
    {
        $cache = new FileDriver();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid@key');
        $cache->has('invalid@key');
    }

    public function test_invalid_key_throws_on_get_multiple(): void
    {
        $cache = new FileDriver();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid key');
        $cache->getMultiple(['valid', 'invalid key']);
    }

    public function test_does_not_instantiate_objects_on_read(): void
    {
        // Regression test: a tampered cache file containing a serialized
        // object must NOT be instantiated on read (POP-chain RCE
        // prevention). With allowed_classes => false, the object is treated
        // as a cache miss rather than being constructed.
        $cache = new FileDriver();
        $cache->set('key', 'value');

        // Overwrite the cache file with a serialized object payload.
        $path = $this->cacheDir . DIRECTORY_SEPARATOR . 'key.cache';
        $payload = '0|' . serialize(new \stdClass());
        file_put_contents($path, $payload);

        // Reading must not return the object (and must not instantiate it).
        $this->assertSame('fallback', $cache->get('key', 'fallback'));
    }
}