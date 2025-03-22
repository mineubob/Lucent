<?php

namespace Lucent\Filesystem;

use Exception;
use function PHPUnit\Framework\throwException;

/**
 * File class for working with individual files
 *
 * This class provides methods to manipulate and retrieve information about files
 * in the filesystem including reading, writing, copying, moving and deletion.
 */
class File
{
    /**
     * The file path
     *
     * @var string
     */
    public protected(set) string $path;

    /**
     * Create a new File instance
     *
     * @param string $path The path to the file
     * @throws Exception If the file does not exist
     */
    public function __construct(string $path)
    {
        $this->path = $path;

        if(!file_exists($this->path)) {
            throwException(new Exception("File {$this->path} does not exist"));
        }
    }

    /**
     * Get the file size in bytes
     *
     * @return int The file size in bytes, or 0 if the file doesn't exist
     */
    public function getSize(): int
    {
        return file_exists($this->path) ? filesize($this->path) : 0;
    }

    /**
     * Get the contents of the file
     *
     * @return string The file contents, or an empty string if the file doesn't exist
     */
    public function getContents(): string
    {
        return file_exists($this->path) ? file_get_contents($this->path) : '';
    }

    /**
     * Check if the file exists
     *
     * @return bool True if the file exists, false otherwise
     */
    public function exists(): bool
    {
        return file_exists($this->path);
    }

    /**
     * Delete the file
     *
     * @return bool True if the file was successfully deleted, false otherwise
     */
    public function delete(): bool
    {
        return file_exists($this->path) && unlink($this->path);
    }

    /**
     * Append content to the file
     *
     * @param string $content The content to append
     * @return bool True if the content was successfully appended, false otherwise
     */
    public function append(string $content): bool
    {
        return file_put_contents($this->path, $content, FILE_APPEND) !== false;
    }

    /**
     * Write content to the file
     *
     * @param string $content The content to write
     * @param bool $append Whether to append the content (true) or overwrite (false)
     * @return bool True if the content was successfully written, false otherwise
     */
    public function write(string $content, bool $append = false): bool
    {
        $flags = $append ? FILE_APPEND : 0;
        return file_put_contents($this->path, $content, $flags) !== false;
    }

    /**
     * Rename the file to a new path
     *
     * @param string $newPath The new path for the file
     * @param bool $absolute Whether the new path is absolute (true) or relative to root path (false)
     * @return bool True if the file was successfully renamed, false otherwise
     */
    public function rename(string $newPath, bool $absolute = false): bool
    {
        if(!$absolute){
            $newPath = \Lucent\Facades\File::rootPath().$newPath;
        }

        if (!file_exists($this->path)) {
            return false;
        }

        if (rename($this->path, $newPath)) {
            $this->path = $newPath;
            return true;
        }

        return false;
    }

    /**
     * Copy the file to a new location
     *
     * @param string $destination The destination path for the copied file
     * @param bool $absolute Whether the destination path is absolute (true) or relative to root path (false)
     * @return bool True if the file was successfully copied, false otherwise
     */
    public function copy(string $destination, bool $absolute = false): bool
    {
        if(!$absolute){
            $destination = \Lucent\Facades\File::rootPath().$destination;
        }

        return file_exists($this->path) && copy($this->path, $destination);
    }

    /**
     * Move a file from its current location to another
     *
     * This method will create the destination directory if it doesn't exist.
     * If successful, the file's path property will be updated to the new location.
     *
     * @param string $destination Path to the destination file
     * @param bool $absolute Whether the destination path is absolute (true) or relative to root path (false)
     * @return bool True on success, false on failure
     */
    public function move(string $destination, bool $absolute = false): bool
    {
        // Check if the source file exists
        if (!file_exists($this->path)) {
            return false;
        }

        if(!$absolute){
            $destination = \Lucent\Facades\File::rootPath().$destination;
        }

        // Create destination directory if it doesn't exist
        $destDir = dirname($destination);
        if (!is_dir($destDir)) {
            mkdir($destDir, 0755, true);
        }

        // Move the file
        $result = rename($this->path, $destination);

        if ($result) {
            $this->path = $destination;
        }

        return $result;
    }
}