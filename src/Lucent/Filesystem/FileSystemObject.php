<?php

namespace Lucent\Filesystem;

/**
 * Abstract base class for filesystem objects
 *
 * Provides common functionality for both files and folders in the filesystem
 */
abstract class FileSystemObject
{
    /**
     * The absolute path to the filesystem object
     *
     * @var string
     */
    public protected(set) string $path;

    /**
     * Gets the parent directory of this filesystem object
     *
     * @return Folder The parent directory as a Folder object
     */
    public function getDirectory(): Folder
    {
        return new Folder(dirname($this->path), true);
    }

    /**
     * Gets the name of the filesystem object
     *
     * @return string The basename of the path
     */
    public function getName(): string
    {
        return basename($this->path);
    }

    /**
     * Checks if the filesystem object exists
     *
     * @return bool True if exists, false otherwise
     */
    abstract public function exists(): bool;

    /**
     * Deletes the filesystem object
     *
     * @return bool True if successfully deleted, false otherwise
     */
    abstract public function delete();

    /**
     * Creates the filesystem object
     *
     * @param mixed $params Creation parameters, varies by implementation
     * @return bool True if successfully created, false otherwise
     */
    abstract public function create(mixed $params) : bool;
}