<?php

namespace Lucent\Filesystem;

use Lucent\Facades\FileSystem;
use Lucent\Facades\Log;

/**
 * File class for handling file operations
 *
 * Represents a file in the filesystem and provides methods for common file operations
 * such as creating, reading, writing, copying, and deleting files.
 */
class File extends FileSystemObject
{
    /**
     * Creates a new File instance
     *
     * @param string $path The path to the file (relative or absolute)
     * @param mixed $content Optional content to write to the file
     * @param bool $absolute Whether the provided path is absolute (true) or relative to root (false)
     */
    public function __construct(string $path, mixed $content = null, bool $absolute = false)
    {
        if(!$absolute) {
            $path = FileSystem::rootPath() . $path;
        }

        $this->path = $path;

        if($content !== null){
            $this->create($content);
        }
    }

    /**
     * Creates the file with the given content
     *
     * Creates parent directories if they don't exist
     *
     * @param mixed $params Content to write to the file
     * @return bool True if successful, false otherwise
     */
    public function create(mixed $params = null) : bool
    {
        if(!file_exists($this->path)) {
            $directory = dirname($this->path);

            // Create directory if it doesn't exist
            if (!is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            if($params !== null){
                Log::channel("db")->debug("Using write");
                return $this->write($params);
            }else{
                Log::channel("db")->debug("Using touch");
                return touch($this->path);
            }

        }

        return true;
    }

    /**
     * Checks if the file exists
     *
     * @return bool True if the file exists, false otherwise
     */
    public function exists(): bool
    {
        return file_exists($this->path);
    }

    public function setPermissions(int $permissions) : bool
    {
        return chmod($this->path, $permissions);
    }

    /**
     * Deletes the file
     *
     * @return bool True if successfully deleted, false otherwise
     */
    public function delete(): bool
    {
        return unlink($this->path);
    }

    /**
     * Copies the file to a new location
     *
     * @param string $name The name for the copied file
     * @param Folder $folder The destination folder
     * @param bool $absolute Whether the path is absolute
     * @return FileSystemObject|null The new file object if successful, null otherwise
     */
    public function copy(string $name, Folder $folder, bool $absolute = false): ?FileSystemObject
    {
        // Construct destination path
        $destinationPath = $folder->path.DIRECTORY_SEPARATOR.$name;

        // Check source file
        if (!file_exists($this->path)) {
            error_log("COPY ERROR: Source file does not exist: {$this->path}");
            return null;
        }

        // Check destination folder
        if (!is_dir($folder->path)) {
            error_log("COPY ERROR: Destination folder does not exist: {$folder->path}");
            return null;
        }

        // Check write permissions
        if (!is_writable($folder->path)) {
            error_log("COPY ERROR: Destination folder is not writable: {$folder->path}");
            return null;
        }

        // Create the new file object
        $copy = new File($destinationPath, "", true);

        // Perform copy operation
        $success = @copy($this->path, $copy->path);

        // Check for PHP errors during copy
        if (!$success) {
            $error = error_get_last();
            error_log("COPY ERROR: PHP error during copy: " . ($error ? $error['message'] : 'Unknown error'));
            error_log("  From: {$this->path}");
            error_log("  To: {$copy->path}");
            return null;
        }

        // Double-check that file now exists
        if (!$copy->exists()) {
            error_log("COPY ERROR: File copied but doesn't exist at destination: {$copy->path}");
            return null;
        }

        return $copy;
    }

    /**
     * Writes content to the file
     *
     * @param mixed $content Content to write to the file
     * @return bool True if successful, false otherwise
     */
    public function write(mixed $content = null): bool
    {
        return file_put_contents($this->path, $content) !== false;
    }

    /**
     * Gets the contents of the file
     *
     * @return string The file contents
     */
    public function getContents(): string
    {
        return file_get_contents($this->path);
    }
}