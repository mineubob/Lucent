<?php

namespace Lucent\Filesystem;

use Lucent\Facades\FileSystem;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Folder class for handling directory operations
 *
 * Represents a directory in the filesystem and provides methods for common
 * directory operations such as creating, searching, and deleting folders.
 */
class Folder extends FileSystemObject
{
    /**
     * Creates a new Folder instance
     *
     * @param string $path The path to the folder (relative or absolute)
     * @param bool $absolute Whether the provided path is absolute (true) or relative to root (false)
     */
    public function __construct(string $path, bool $absolute = false){
        if(!$absolute){
            $path = FileSystem::rootPath().$path;
        }
        $this->path = $path;
    }

    /**
     * Gets all files in the folder
     *
     * @param bool $recursive Whether to get files recursively from subdirectories
     * @return array Array of File objects
     */
    public function getFiles(bool $recursive = false) : array
    {
        $collection = $this->search()->onlyFiles();

        if($recursive){
            $collection->recursive();
        }

        return $collection->collect();
    }

    /**
     * Creates a search collection for this folder
     *
     * @return FileSystemCollection A new collection for searching within this folder
     */
    public function search() : FileSystemCollection
    {
        return new FileSystemCollection($this);
    }

    /**
     * Checks if the folder exists
     *
     * @return bool True if the folder exists, false otherwise
     */
    public function exists() : bool{
        return is_dir($this->path);
    }

    /**
     * Deletes the folder and all its contents
     *
     * @return bool True if successfully deleted, false otherwise
     */
    public function delete() : bool
    {
        if (!$this->exists()) {
            return false;
        }

        $it = new RecursiveDirectoryIterator($this->path, RecursiveDirectoryIterator::SKIP_DOTS);
        $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }

        return rmdir($this->path);
    }

    /**
     * Creates the folder
     *
     * @param mixed $params Permissions for the folder (numeric, e.g., 0777)
     * @return bool True if successfully created, false otherwise
     * @throws \Exception If params is not numeric
     */
    public function create(mixed $params = 0777) : bool
    {
        if(!is_numeric($params)){
            throw new \Exception("Params must be numeric permission value ie, 0777");
        }
        return mkdir($this->path, $params, true);
    }

    /**
     * Copies all files from this folder to another folder
     *
     * @param FileSystemObject $object Destination folder
     * @return bool True if successful, false otherwise
     */
    public function copy(FileSystemObject $object): bool
    {
        $outcome = false;

        // Copy all files from the source folder (this) to the destination folder
        foreach ($this->search()->collect() as $fileSystemObject) {
            if ($fileSystemObject instanceof File) {
                $newFile = $fileSystemObject->copy($fileSystemObject->getName(), $object);
                $outcome = $newFile->exists();
                continue;
            }
            if($fileSystemObject instanceof Folder){
                // $fileSystemObject->copy($folder->getName(), $folder);
            }
        }

        // Return the outcome
        return $outcome;
    }
}