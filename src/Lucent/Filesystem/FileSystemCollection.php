<?php

namespace Lucent\Filesystem;

use Exception;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * FileSystemCollection class for filtering and collecting filesystem objects
 *
 * Provides a fluent interface for searching and filtering files and folders
 * within a directory based on various criteria.
 */
class FileSystemCollection
{
    /**
     * Pattern type constants
     */
    const int PATTERN_REGEX = 0;
    const int PATTERN_WILDCARD = 1;

    /**
     * Item type constants
     */
    const int TYPE_FILE = 0;
    const int TYPE_FOLDER = 1;
    const int TYPE_BOTH = -1;

    /**
     * The folder to search in
     *
     * @var Folder
     */
    private Folder $folder;

    /**
     * File extensions to filter by
     *
     * @var array
     */
    private array $extension;

    /**
     * Patterns to match filenames against
     *
     * @var array
     */
    private array $patterns;

    /**
     * Patterns to exclude filenames
     *
     * @var array
     */
    private array $exclude;

    /**
     * Whether to search recursively
     *
     * @var bool
     */
    private bool $recursive;

    /**
     * Type of items to collect (files, folders, or both)
     *
     * @var int
     */
    private int $itemType = -1;

    /**
     * Creates a new FileSystemCollection
     *
     * @param Folder $folder The folder to search in
     */
    public function __construct(Folder $folder)
    {
        $this->folder = $folder;
        $this->extension = [];
        $this->patterns = [];
        $this->exclude = [];
        $this->recursive = false;
    }

    /**
     * Filters by file extension
     *
     * @param string|array $extension One or more extensions to filter by
     * @return FileSystemCollection This collection for method chaining
     */
    public function extension(string|array $extension) : FileSystemCollection
    {
        if(is_string($extension)) {
            $this->extension[] = strtolower($extension);
        }
        if(is_array($extension)) {
            $this->extension = array_merge($this->extension, array_map('strtolower',$extension));
        }

        return $this;
    }

    /**
     * Filters by filename pattern
     *
     * @param string|array $pattern One or more patterns to match against
     * @param int $type The pattern type (PATTERN_REGEX or PATTERN_WILDCARD)
     * @return FileSystemCollection This collection for method chaining
     */
    public function pattern(string|array $pattern, int $type = 0) : FileSystemCollection
    {
        if(is_string($pattern)) {
            $this->patterns[$type][] = $pattern;
        }
        if(is_array($pattern)) {
            $this->patterns[$type] = array_merge($this->patterns[$type], $pattern);
        }
        return $this;
    }

    /**
     * Sets recursive search mode
     *
     * @return FileSystemCollection This collection for method chaining
     */
    public function recursive() : FileSystemCollection
    {
        $this->recursive = true;
        return $this;
    }

    /**
     * Excludes filenames matching the given pattern
     *
     * @param string|array $exclude Pattern(s) to exclude
     * @param int $type The pattern type (PATTERN_REGEX or PATTERN_WILDCARD)
     * @return FileSystemCollection This collection for method chaining
     * @throws Exception If the pattern type is invalid
     */
    public function exclude(string|array $exclude, int $type = 0) : FileSystemCollection
    {
        if($type > 1 || $type < 0) {
            throw new Exception("Invalid type provided, type must be PATTERN_REGEX or PATTERN_WILDCARD");
        }

        // Initialize the array if it doesn't exist
        if (!isset($this->exclude[$type])) {
            $this->exclude[$type] = [];
        }

        if(is_string($exclude)) {
            $this->exclude[$type][] = $exclude;
        }
        if(is_array($exclude)) {
            if (!isset($this->exclude[$type])) {
                $this->exclude[$type] = [];
            }
            $this->exclude[$type] = array_merge($this->exclude[$type], $exclude);
        }

        return $this;
    }

    /**
     * Specify the type of items to collect (files, folders, or both)
     *
     * @param int $type One of TYPE_FILE, TYPE_FOLDER, or TYPE_BOTH
     * @return FileSystemCollection This collection for method chaining
     * @throws Exception If the type is invalid
     */
    public function ofType(int $type) : FileSystemCollection
    {
        if ($type !== self::TYPE_FILE && $type !== self::TYPE_FOLDER && $type !== self::TYPE_BOTH) {
            throw new Exception("Invalid type provided, type must be TYPE_FILE, TYPE_FOLDER, or TYPE_BOTH");
        }

        $this->itemType = $type;
        return $this;
    }

    /**
     * Shorthand method to collect only files
     *
     * @return FileSystemCollection This collection for method chaining
     */
    public function onlyFiles() : FileSystemCollection
    {
        return $this->ofType(self::TYPE_FILE);
    }

    /**
     * Shorthand method to collect only folders
     *
     * @return FileSystemCollection This collection for method chaining
     */
    public function onlyFolders() : FileSystemCollection
    {
        return $this->ofType(self::TYPE_FOLDER);
    }

    /**
     * Shorthand method to collect both files and folders
     *
     * @return FileSystemCollection This collection for method chaining
     */
    public function filesAndFolders() : FileSystemCollection
    {
        return $this->ofType(self::TYPE_BOTH);
    }

    /**
     * Executes the search and returns the collection of matching items
     *
     * @return array Array of File and/or Folder objects matching the criteria
     */
    public function collect(): array
    {
        $items = [];

        // Use FilesystemIterator for both recursive and non-recursive cases
        if ($this->recursive) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->folder->path, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
        } else {
            $iterator = new FilesystemIterator($this->folder->path, FilesystemIterator::SKIP_DOTS);
        }

        foreach ($iterator as $fileInfo) {
            // Skip based on item type
            if (($this->itemType === self::TYPE_FILE && !$fileInfo->isFile()) ||
                ($this->itemType === self::TYPE_FOLDER && !$fileInfo->isDir())) {
                continue;
            }

            // Handle folders
            if ($fileInfo->isDir()) {
                $items[] = new Folder($fileInfo->getRealPath(), true);
                continue;
            }

            // For files, apply filtering
            $filename = $fileInfo->getFilename();

            // Skip if extension doesn't match
            if (!empty($this->extension) &&
                !in_array(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), $this->extension)) {
                continue;
            }

            // Skip if pattern matching is required but fails
            if (!empty($this->patterns) && !$this->matchesAnyPattern($filename, $this->patterns)) {
                continue;
            }

            // Skip if file matches any exclude pattern
            if (!empty($this->exclude) && $this->matchesAnyPattern($filename, $this->exclude)) {
                continue;
            }

            $items[] = new File($fileInfo->getRealPath(), "", true);
        }

        return $items;
    }

    /**
     * Check if a filename matches any pattern in the given pattern sets
     *
     * @param string $filename The filename to check
     * @param array $patternSets Array of patterns to check against
     * @return bool True if the filename matches any pattern, false otherwise
     */
    private function matchesAnyPattern(string $filename, array $patternSets): bool
    {
        // Check regex patterns
        if (isset($patternSets[self::PATTERN_REGEX])) {
            if (array_any($patternSets[self::PATTERN_REGEX], fn($pattern) => preg_match($pattern, $filename))) {
                return true;
            }
        }

        // Check wildcard patterns
        if (isset($patternSets[self::PATTERN_WILDCARD])) {
            if (array_any($patternSets[self::PATTERN_WILDCARD], fn($pattern) => fnmatch($pattern, $filename))) {
                return true;
            }
        }

        return false;
    }
}