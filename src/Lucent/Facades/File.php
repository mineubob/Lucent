<?php

namespace Lucent\Facades;

use Exception;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * File facade for file system operations
 *
 * This class provides static methods for common file operations including
 * file creation, retrieval, and directory listing.
 */
class File
{
    /**
     * The root path used for resolving relative paths
     *
     * @var string
     */
    private static string $root_path;

    /**
     * Get the current root path
     *
     * @return string The current root path
     */
    public static function rootPath() : string{
        return self::$root_path;
    }

    /**
     * Override the default root path
     *
     * @param string $path The new root path
     * @return void
     */
    public static function overrideRootPath(string $path) : void
    {
        self::$root_path = $path;
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
    public static function getFiles(?string $directory = null, string|array|null $extensions = null, bool $recursive = true) : array
    {
        if($directory == null) {
            $directory = self::rootPath();
        }else{
            $directory = self::$root_path.$directory;
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
                new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
        } else {
            $iterator = new \DirectoryIterator($directory);
        }

        foreach ($iterator as $fileInfo) {
            if($fileInfo->isFile()) {
                // If extension filter is provided, check if file matches
                if ($extensions !== null) {
                    $extension = strtolower(pathinfo($fileInfo->getFilename(), PATHINFO_EXTENSION));
                    if (!in_array($extension, $extensions)) {
                        continue; // Skip files that don't match the extensions
                    }
                }

                $items[] = new \Lucent\Filesystem\File($fileInfo->getRealPath());
            }
        }

        return $items;
    }

    /**
     * Get a file instance if it exists, or null if it doesn't
     *
     * @param string $path Path to the file (relative to root path)
     * @return \Lucent\Filesystem\File|null File instance or null if file doesn't exist
     */
    public static function get(string $path): ?\Lucent\Filesystem\File
    {
        $fullPath = self::$root_path.$path;
        return file_exists($fullPath) ? new \Lucent\Filesystem\File($fullPath) : null;
    }

    /**
     * Create a new file with optional content
     *
     * This method creates the directory structure if it doesn't exist
     * and initializes the file with the provided content if any.
     *
     * @param string $path Path to the file (relative to root path)
     * @param string $content Optional initial content for the file
     * @return \Lucent\Filesystem\File The file instance
     */
    public static function create(string $path, string $content = ''): \Lucent\Filesystem\File
    {
        $fullPath = self::$root_path.$path;
        $file = new \Lucent\Filesystem\File($fullPath);

        // Create directory if it doesn't exist
        $directory = dirname($fullPath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        // Create the file with initial content if provided
        if (!empty($content)) {
            $file->write($content);
        }

        return $file;
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
}