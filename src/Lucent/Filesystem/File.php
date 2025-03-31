<?php

namespace Lucent\Filesystem;

use Lucent\Facades\FileSystem;

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
    public function __construct(string $path, mixed $content = "", bool $absolute = false)
    {
        if(!$absolute) {
            $path = FileSystem::rootPath() . $path;
        }

        $this->path = $path;

        if($content !== ""){
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
    public function create(mixed $params) : bool
    {
        if(!file_exists($this->path)) {
            $directory = dirname($this->path);

            // Create directory if it doesn't exist
            if (!is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            $outcome = $this->write($params);
        }else{
            $outcome = true;
        }

        return $outcome;
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
        $copy = new File($folder->path.DIRECTORY_SEPARATOR.$name, absolute: true);

        // Copy the file
        if(copy($this->path, $copy->path) && $copy->exists()) {
            return $copy;
        }

        return null;
    }

    /**
     * Writes content to the file
     *
     * @param mixed $content Content to write to the file
     * @return bool True if successful, false otherwise
     */
    public function write(mixed $content): bool
    {
        return file_put_contents($this->path, $content);
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