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
 * (default `storage/cache`). Keys are used directly as filenames — they are
 * already restricted to the filesystem-safe characters `[A-Za-z0-9_.]` — so
 * there is no hashing and no collision risk. Each file stores the absolute
 * expiry timestamp followed by the serialized value.
 *
 * Values must be serializable via PHP's native `serialize()`.
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
        $this->directory = FileSystem::isAbsolute($directory)
            ? $directory
            : FileSystem::rootPath() . DIRECTORY_SEPARATOR . $directory;
    }

    /**
     * Resolve the absolute path for a cache key.
     *
     * @param string $key The cache key
     * @return string Absolute file path
     */
    private function pathFor(string $key): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . $key . '.cache';
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

        return unserialize(substr($contents, $separator + 1));
    }

    /**
     * @inheritDoc
     */
    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        $this->validateKey($key);

        $expires = $this->expiryFromTtl($ttl);

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