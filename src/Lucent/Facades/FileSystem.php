<?php
declare(strict_types=1);


namespace Lucent\Facades;

use Exception;
use FilesystemIterator;
use Lucent\Filesystem\Folder;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Lucent\Filesystem\File;

/**
 * FileSystem facade for file system operations
 *
 * This class provides static methods for common file operations including
 * file creation, retrieval, and directory listing.
 */
class FileSystem
{
    /**
     * The root path used for resolving relative paths
     *
     * @var string
     */
    private static string $root_path;

    /**
     * Get the current root path. The trailing `/` will be removed before this function is called.
     *
     * @return string The current root path
     */
    public static function rootPath(): string
    {
        return self::$root_path;
    }

    /**
     * Override the default root path
     *
     * @param string $path The new root path
     * @return void
     */
    public static function overrideRootPath(string $path): void
    {
        self::$root_path = rtrim($path, DIRECTORY_SEPARATOR);
    }

    /**
     * Determine whether a path is absolute.
     *
     * Recognises Unix-style paths (leading "/") and Windows-style paths
     * (leading drive letter such as "C:\" or "C:/").
     *
     * @param string $path The path to test
     * @return bool True if the path is absolute, false otherwise
     */
    public static function isAbsolute(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        // Unix-style absolute path.
        if (str_starts_with($path, '/')) {
            return true;
        }

        // Windows-style absolute path (e.g. C:\ or C:/).
        return DIRECTORY_SEPARATOR === '\\'
            && preg_match('/^[A-Z]:[\\\\\/]/i', $path) === 1;
    }

    /**
     * Resolve a path against the configured root.
     *
     * The path is always treated as relative to the root: a leading
     * separator is stripped and the root path is prepended. This matches
     * the convention used by {@see File} and {@see Folder}, where a leading
     * `/` means "relative to root" rather than an absolute filesystem path.
     * The result is normalized but not checked against the containment
     * guard — callers that construct {@see File} or {@see Folder} objects
     * get that check from the constructor.
     *
     * @param string $path The path to resolve (relative to root)
     * @return string The resolved absolute path
     */
    public static function resolvePath(string $path): string
    {
        return self::rootPath() . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);
    }

    /**
     * Resolve a path to an absolute filesystem path.
     *
     * Absolute paths are returned unchanged; relative paths are resolved
     * against the root via {@see resolvePath()}. Unlike {@see resolvePath()},
     * a leading separator is treated as a filesystem-absolute path rather
     * than root-relative.
     *
     * @param string $path The path to resolve (relative or absolute)
     * @return string The resolved absolute path
     */
    public static function absolutePath(string $path): string
    {
        return self::isAbsolute($path)
            ? $path
            : self::resolvePath($path);
    }

    /**
     * Normalize a path lexically, resolving `.` and `..` segments.
     *
     * This is a pure string operation — it does not touch the filesystem, so
     * it works for paths that do not exist yet. It preserves the leading
     * separator for absolute paths, Windows drive-letter prefixes, and keeps
     * leading `..` segments for relative paths.
     *
     * @param string $path The path to normalize
     * @return string The normalized path
     */
    public static function normalizePath(string $path): string
    {
        if ($path === '') {
            return '';
        }

        // Preserve a Windows drive-letter prefix (e.g. "C:").
        $prefix = '';
        if (preg_match('/^[A-Za-z]:/', $path)) {
            $prefix = substr($path, 0, 2);
            $path = substr($path, 2);
        }

        // Preserve whether the path is absolute.
        $isAbsolute = str_starts_with($path, '/') || str_starts_with($path, '\\');
        if ($isAbsolute) {
            $path = ltrim($path, '/\\');
        }

        $segments = preg_split('/[\/\\\\]+/', $path);
        $stack = [];

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if (!empty($stack) && end($stack) !== '..') {
                    array_pop($stack);
                } elseif (!$isAbsolute) {
                    // Preserve leading ".." for relative paths.
                    $stack[] = '..';
                }
                continue;
            }

            $stack[] = $segment;
        }

        $result = implode(DIRECTORY_SEPARATOR, $stack);

        if ($isAbsolute) {
            $result = DIRECTORY_SEPARATOR . $result;
        }

        return $prefix . $result;
    }

    /**
     * Determine whether a path is contained within the configured root path.
     *
     * Resolves the path (handling `..` segments and symlinks) and checks it
     * does not escape the root. Works for both existing and non-existent
     * paths — the latter are resolved via their parent directory.
     *
     * @param string $path The absolute path to check
     * @return bool True if the path is within the root, false otherwise
     */
    public static function isWithinRoot(string $path): bool
    {
        $path = self::normalizePath($path);

        $root = realpath(self::$root_path);
        if ($root === false) {
            // Root doesn't exist yet; fall back to a lexical comparison.
            $root = rtrim(self::$root_path, DIRECTORY_SEPARATOR);
        }
        $root = rtrim($root, DIRECTORY_SEPARATOR);

        // Resolve the deepest existing ancestor of $path, then re-append the
        // remaining (possibly non-existent) segments lexically. This handles
        // paths whose intermediate directories don't exist yet (e.g. a File
        // created before its parent Folder).
        $resolved = $path;
        $suffix = '';
        while (true) {
            $real = realpath($resolved);
            if ($real !== false) {
                $resolved = $real . $suffix;
                break;
            }
            $parent = dirname($resolved);
            if ($parent === $resolved) {
                // Reached the filesystem root without finding an existing ancestor.
                return false;
            }
            $suffix = DIRECTORY_SEPARATOR . basename($resolved) . $suffix;
            $resolved = $parent;
        }

        return $resolved === $root
            || str_starts_with($resolved, $root . DIRECTORY_SEPARATOR);
    }

    /**
     * Get all files in a directory recursively with optional extension filtering
     *
     * @param string|null $directory The directory to scan (relative to root path), or null for root path
     * @param string|array|null $extensions Optional extensions to filter by (e.g., 'php' or ['php', 'js'])
     * @param bool $recursive Whether to search recursively in subdirectories
     * @return array Array of File objects representing files in the directory
     * @throws Exception
     */
    public static function getFiles(?string $directory = null, string|array|null $extensions = null, bool $recursive = true): array
    {
        // Determine the directory path
        if ($directory == null) {
            $directoryPath = self::rootPath();
        } else {
            $cleanDir = ltrim($directory, DIRECTORY_SEPARATOR);
            $directoryPath = self::normalizePath(self::$root_path . DIRECTORY_SEPARATOR . $cleanDir);
        }

        // Normalize extensions to array and lowercase if provided
        if ($extensions !== null) {
            $extensions = is_array($extensions) ? $extensions : [$extensions];
            $extensions = array_map('strtolower', $extensions);
        }

        $items = [];

        // Set up the appropriate iterator based on a recursive flag
        if ($recursive) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directoryPath, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
        } else {
            $iterator = new \DirectoryIterator($directoryPath);
        }

        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isFile()) {
                // If extension filter is provided, check if file matches
                if ($extensions !== null) {
                    $extension = strtolower(pathinfo($fileInfo->getFilename(), PATHINFO_EXTENSION));
                    if (!in_array($extension, $extensions)) {
                        continue; // Skip files that don't match the extensions
                    }
                }

                // Create a file object with absolute path (true)
                $items[] = new File($fileInfo->getRealPath(), true);
            }
        }

        return $items;
    }

    /**
     * Get a file instance if it exists, or null if it doesn't
     *
     * @param string $path Path to the file (relative to root path)
     * @return File|null File instance or null if file doesn't exist
     */
    public static function get(string $path): ?File
    {
        try {
            // Clean the path
            $cleanPath = ltrim($path, DIRECTORY_SEPARATOR);
            $fullPath = self::normalizePath(self::$root_path . DIRECTORY_SEPARATOR . $cleanPath);

            // Containment guard: reject paths that escape the root.
            if (!self::isWithinRoot($fullPath)) {
                return null;
            }

            // Contract: return null if the file doesn't exist.
            if (!file_exists($fullPath)) {
                return null;
            }

            return new File($fullPath, true);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Create a new file with optional content
     *
     * This method creates the directory structure if it doesn't exist
     * and initializes the file with the provided content if any.
     *
     * @param string $path Path to the file (relative to root path)
     * @param string $content Optional initial content for the file
     * @return File|null The file instance or null on failure
     */
    public static function create(string $path, string $content = ''): ?File
    {
        // Clean the path
        $cleanPath = ltrim($path, DIRECTORY_SEPARATOR);
        $fullPath = self::normalizePath(self::$root_path . DIRECTORY_SEPARATOR . $cleanPath);

        // Containment guard: reject paths that escape the root.
        if (!self::isWithinRoot($fullPath)) {
            return null;
        }

        // Create directory if it doesn't exist
        $directory = dirname($fullPath);
        if (!is_dir($directory)) {
            if (!mkdir($directory, 0755, true)) {
                return null;
            }
        }

        // Create the file with initial content
        if (file_put_contents($fullPath, $content) === false) {
            return null;
        }

        try {
            // Return a new File instance with absolute path
            return new File($fullPath, true);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Format file size in a human-readable format
     *
     * @param int $bytes File size in bytes
     * @return string Formatted file size
     */
    public static function formatFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);

        return round($bytes, 2) . ' ' . $units[$pow];
    }

    public static function root(): Folder
    {
        return new Folder(self::$root_path, true);
    }
}
