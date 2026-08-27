<?php

namespace Lucent\Cache\Drivers;

use DateInterval;
use Lucent\Cache\Cache;
use Lucent\Facades\FileSystem;
use Lucent\Filesystem\File;

/**
 * File-based cache driver.
 *
 * Persists each value as its own file under the configured cache directory
 * (default `storage/cache`). Keys are hashed with SHA-256 for the filename
 * so the supported key length is not bounded by the filesystem's 255-byte
 * filename component limit. The hash is deterministic and collision-free in
 * practice; the original key is not stored on disk.
 *
 * Each file stores the absolute expiry timestamp followed by the serialized
 * value. Values must be serializable via PHP's native `serialize()`.
 */
class FileDriver extends Cache
{
    /**
     * Cache directory path (absolute).
     */
    private string $directory;

    /**
     * @param string $directory Cache directory, relative to the project root or absolute
     */
    public function __construct(string $directory = 'storage/cache')
    {
        $this->directory = FileSystem::normalizePath(
            FileSystem::isAbsolute($directory)
                ? $directory
                : FileSystem::rootPath() . DIRECTORY_SEPARATOR . $directory
        );
    }

    /**
     * Resolve the absolute path for a cache key.
     *
     * The key is hashed with SHA-256 so the filename is a fixed 64 hex
     * characters regardless of key length, keeping it well under the
     * filesystem's 255-byte filename component limit.
     *
     * @param string $key The cache key
     * @return string Absolute file path
     */
    private function pathFor(string $key): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . hash('sha256', $key) . '.cache';
    }

    /**
     * Ensure the cache directory exists.
     *
     * @return void
     */
    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0755, true);
        }
    }

    /**
     * @inheritDoc
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $this->validateKey($key);

        $file = new File($this->pathFor($key), null, true);

        if (!$file->exists()) {
            return $default;
        }

        $contents = $file->getContents();
        $separator = strpos($contents, '|');

        if ($separator === false) {
            return $default;
        }

        $expires = (int) substr($contents, 0, $separator);

        if ($expires !== 0 && $expires <= time()) {
            $file->delete();
            return $default;
        }

        // Restrict object instantiation to prevent PHP object injection
        // (POP-chain RCE) from a tampered or attacker-influenced cache file.
        // `allowed_classes => false` returns __PHP_Incomplete_Class for any
        // object in the payload instead of instantiating it, which is safe
        // for the scalar/array values this cache stores.
        $value = unserialize(substr($contents, $separator + 1), ['allowed_classes' => false]);

        // A value that unserialized to an incomplete class is untrusted —
        // treat it as a cache miss rather than returning a broken object.
        if ($value instanceof \__PHP_Incomplete_Class) {
            $file->delete();
            return $default;
        }

        return $value;
    }

    /**
     * @inheritDoc
     */
    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        $this->validateKey($key);

        $expires = $this->expiryFromTtl($this->resolveTtl($ttl));

        if ($expires !== null && $expires <= time()) {
            return $this->delete($key);
        }

        $this->ensureDirectory();

        $file = new File($this->pathFor($key), null, true);

        $payload = ($expires ?? 0) . '|' . serialize($value);

        return $file->write($payload);
    }

    /**
     * @inheritDoc
     */
    public function delete(string $key): bool
    {
        $this->validateKey($key);

        $file = new File($this->pathFor($key), null, true);

        if (!$file->exists()) {
            return true;
        }

        return $file->delete();
    }

    /**
     * @inheritDoc
     */
    public function clear(): bool
    {
        if (!is_dir($this->directory)) {
            return true;
        }

        $success = true;

        foreach (glob($this->directory . DIRECTORY_SEPARATOR . '*.cache') ?: [] as $path) {
            if (!unlink($path)) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * @inheritDoc
     */
    public function has(string $key): bool
    {
        $this->validateKey($key);

        $file = new File($this->pathFor($key), null, true);

        if (!$file->exists()) {
            return false;
        }

        $contents = $file->getContents();
        $separator = strpos($contents, '|');

        if ($separator === false) {
            return false;
        }

        $expires = (int) substr($contents, 0, $separator);

        if ($expires !== 0 && $expires <= time()) {
            $file->delete();
            return false;
        }

        return true;
    }
}