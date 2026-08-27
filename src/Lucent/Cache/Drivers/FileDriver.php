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
 * Files are sharded into two levels of 2-character subdirectories derived
 * from the hash (e.g. `storage/cache/ab/cd/<sha256>.cache`). This keeps the
 * number of files per directory bounded so the store scales to large cache
 * populations.
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
     * filesystem's 255-byte filename component limit. The first four hex
     * characters form two levels of 2-character subdirectories, so files
     * are spread across up to 65,536 directories.
     *
     * @param string $key The cache key
     * @return string Absolute file path
     */
    private function pathFor(string $key): string
    {
        $hash = hash('sha256', $key);

        return $this->directory
            . DIRECTORY_SEPARATOR . substr($hash, 0, 2)
            . DIRECTORY_SEPARATOR . substr($hash, 2, 2)
            . DIRECTORY_SEPARATOR . $hash . '.cache';
    }

    /**
     * Ensure the cache directory and shard subdirectories exist.
     *
     * @param string $path The full path to the cache file
     * @return void
     */
    private function ensureDirectory(string $path): void
    {
        $directory = dirname($path);

        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
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

        $this->ensureDirectory($path = $this->pathFor($key));

        $file = new File($path, null, true);

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

        foreach ($this->cacheFiles() as $path) {
            if (!unlink($path)) {
                $success = false;
            }
        }

        $this->pruneEmptyDirectories($this->directory);

        return $success;
    }

    /**
     * Recursively yield every cache file under the cache directory.
     *
     * @return \Generator<string> Absolute paths to `.cache` files
     */
    private function cacheFiles(): \Generator
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.cache')) {
                yield $file->getPathname();
            }
        }
    }

    /**
     * Remove empty shard directories after a clear.
     *
     * Walks the tree bottom-up and removes any directory that no longer
     * contains files or subdirectories.
     *
     * @param string $directory Directory to prune
     * @return void
     */
    private function pruneEmptyDirectories(string $directory): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir() && !$this->directoryHasEntries($item->getPathname())) {
                @rmdir($item->getPathname());
            }
        }
    }

    /**
     * Determine whether a directory contains any files or subdirectories.
     *
     * @param string $directory Directory to inspect
     * @return bool True when the directory is not empty
     */
    private function directoryHasEntries(string $directory): bool
    {
        foreach (new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS) as $entry) {
            return true;
        }

        return false;
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