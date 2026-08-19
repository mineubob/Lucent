[Home](../../README.md)

# File System Operations in Lucent

## Introduction

The Lucent Framework provides a powerful and intuitive API for working with files through the `FileSystem` facade and the `File` class. These tools offer a clean abstraction for common file system operations, making it easy to create, read, update, and delete files in your application.

## Table of Contents

- [Overview](#overview)
- [The FileSystem Facade](#the-filesystem-facade)
    - [Root Path Management](#root-path-management)
    - [Getting Files](#getting-files)
    - [Retrieving a File](#retrieving-a-file)
    - [Creating Files](#creating-files)
    - [Normalizing Paths](#normalizing-paths)
    - [Formatting File Sizes](#formatting-file-sizes)
- [The File Class](#the-file-class)
    - [File Properties](#file-properties)
    - [File Operations](#file-operations)
        - [Getting File Contents](#getting-file-contents)
        - [Checking if a File Exists](#checking-if-a-file-exists)
        - [Deleting a File](#deleting-a-file)
        - [Writing to a File](#writing-to-a-file)
        - [Renaming a File](#renaming-a-file)
        - [Copying a File](#copying-a-file)
        - [Getting File Metadata](#getting-file-metadata)
- [The Folder Class](#the-folder-class)
- [Real-World Examples](#real-world-examples)
    - [Example: Log File Management](#example-log-file-management)
    - [Example: File Upload Handling](#example-file-upload-handling)
    - [Example: Configuration Files](#example-configuration-files)
- [Best Practices](#best-practices)

## Overview

The Lucent file system tools provide a simple but powerful abstraction around PHP's native file handling functions. The functionality is divided into three main components:

1. **FileSystem Facade**: A static interface for file system operations that don't require an instance.
2. **File Class**: An object-oriented interface for working with individual files.
3. **Folder Class**: An object-oriented interface for working with directories.

Together, these components provide a comprehensive solution for file management in your Lucent applications.

## The FileSystem Facade

The `FileSystem` facade in Lucent provides static methods for working with the file system, focusing on operations that don't require an instance such as obtaining file lists, retrieving file instances, and creating new files.

### Root Path Management

The `FileSystem` facade uses a root path as the base directory for all relative file operations. By default, this is set to your application's root directory.

```php
use Lucent\Facades\FileSystem;

// Get the current root path
$rootPath = FileSystem::rootPath();
```

### Getting Files

To retrieve all files in a directory (recursively by default):

```php
use Lucent\Facades\FileSystem;

// Get all files in a specific directory (relative to root path)
$files = FileSystem::getFiles('storage/logs');

// Get all files in the root directory
$allFiles = FileSystem::getFiles();

// Non-recursive (top-level only)
$topLevel = FileSystem::getFiles('storage/logs', null, false);

// Filter by extension
$phpFiles = FileSystem::getFiles('routes', 'php');
```

The `getFiles()` method returns an array of `File` objects for each file in the directory, allowing you to perform operations on them:

```php
foreach (FileSystem::getFiles('storage/logs') as $file) {
    echo $file->path . ': ' . FileSystem::formatFileSize(filesize($file->path)) . PHP_EOL;
}
```

### Retrieving a File

To get a specific file:

```php
use Lucent\Facades\FileSystem;

// Get a file by path (relative to root path)
$file = FileSystem::get('storage/app/config.json');

// FileSystem::get() returns null if the file doesn't exist
if ($file !== null) {
    $contents = $file->getContents();
    // Process file contents...
}
```

### Creating Files

To create a new file:

```php
use Lucent\Facades\FileSystem;

// Create an empty file
$file = FileSystem::create('storage/app/newfile.txt');

// Create a file with initial content
$file = FileSystem::create('storage/app/data.json', '{"status": "active"}');
```

The `create()` method automatically creates any required directories in the path and returns a `File` object for further operations.

### Normalizing Paths

To normalize a path lexically — resolving `.` and `..` segments without touching the filesystem:

```php
use Lucent\Facades\FileSystem;

// Resolves ".." and collapses "." segments
$path = FileSystem::normalizePath('/storage/file_test/../test.txt');
// '/storage/test.txt'

// Works for paths that don't exist yet
$path = FileSystem::normalizePath('/storage/does/not/exist/../exist/test.txt');
// '/storage/does/not/exist/test.txt'
```

`normalizePath()` is a pure string operation, so it works on non-existent paths (unlike `realpath()`). It preserves the leading separator for absolute paths, Windows drive-letter prefixes, and keeps leading `..` segments for relative paths.

### Formatting File Sizes

The `FileSystem` facade provides a helper to format byte counts into human-readable strings:

```php
use Lucent\Facades\FileSystem;

echo FileSystem::formatFileSize(1048576); // "1 MB"
echo FileSystem::formatFileSize(1073741824); // "1 GB"
```

## The File Class

The `File` class provides an object-oriented interface for working with individual files. It encapsulates file operations like reading, writing, deleting, and copying files.

### File Properties

The `File` class has the following properties:

| Property | Description | Access |
|----------|-------------|--------|
| `path` | The absolute path to the file | Public (read-only via `public protected(set)`) |

### File Operations

#### Getting File Contents

To retrieve the contents of a file:

```php
use Lucent\Facades\FileSystem;

$file = FileSystem::get('storage/app/config.json');
if ($file !== null) {
    $content = $file->getContents();
    // Process content...
}
```

The `getContents()` method returns the file contents as a string, or an empty string if the file doesn't exist.

#### Checking if a File Exists

To check if a file exists:

```php
use Lucent\Facades\FileSystem;

$file = FileSystem::get('storage/app/config.json');
if ($file !== null && $file->exists()) {
    // File exists, proceed...
}
```

The `exists()` method returns a boolean indicating whether the file exists on the file system.

#### Deleting a File

To delete a file:

```php
use Lucent\Facades\FileSystem;

$file = FileSystem::get('storage/temp/cache.tmp');
if ($file !== null) {
    $result = $file->delete();
    if ($result) {
        // File was successfully deleted
    }
}
```

The `delete()` method returns a boolean indicating whether the deletion was successful.

#### Writing to a File

To write content to a file (replacing existing content):

```php
use Lucent\Facades\FileSystem;

$configFile = FileSystem::get('storage/app/config.json');
if ($configFile !== null) {
    $newConfig = json_encode(['debug' => true, 'cache' => false], JSON_PRETTY_PRINT);
    $configFile->write($newConfig);
}
```

The `write()` method returns a boolean indicating whether the operation was successful. To append content rather than overwrite, use PHP's native `file_put_contents` with the `FILE_APPEND` flag:

```php
// Append content to an existing file
file_put_contents($file->path, $logEntry, FILE_APPEND);
```

#### Renaming a File

To rename a file, use the `rename()` method inherited from `FileSystemObject`. This takes a **new name** (not a full path) — the file stays in its current directory:

```php
use Lucent\Facades\FileSystem;

$file = FileSystem::get('storage/app/old-name.txt');
if ($file !== null) {
    // Rename the file (new name only, stays in the same directory)
    $file->rename('new-name.txt');
}
```

The `rename()` method returns a boolean indicating whether the operation was successful. Note that the file's `path` property will be updated to the new path if the rename is successful. A `RuntimeException` is thrown if the target name already exists.

#### Copying a File

To copy a file, use the `copy()` method. This takes a **new name** and a **destination `Folder`** object:

```php
use Lucent\Facades\FileSystem;

$file = FileSystem::get('storage/app/template.html');
if ($file !== null) {
    // Get the destination folder (creates it if it doesn't exist)
    $copiesFolder = new \Lucent\Filesystem\Folder('storage/app/copies');
    if (!$copiesFolder->exists()) {
        $copiesFolder->create();
    }

    // Copy the file — returns a new File instance or null on failure
    $copy = $file->copy('template-copy.html', $copiesFolder);
    if ($copy !== null) {
        // Copy was successful
    }
}
```

The `copy()` method returns a new `File` instance for the copied file, or `null` if the copy failed.

#### Getting File Metadata

The `File` class provides several methods for retrieving file metadata:

```php
use Lucent\Facades\FileSystem;

$file = FileSystem::get('storage/app/config.json');
if ($file !== null) {
    // Get the file extension (e.g., '.json')
    $extension = $file->getExtension();

    // Get the MIME type (e.g., 'application/json')
    $mimeType = $file->getMimeType();

    // Get the file name (basename)
    $name = $file->getName();

    // Get the parent directory as a Folder object
    $directory = $file->getDirectory();

    // Set file permissions
    $file->setPermissions(0644);
}
```

## The Folder Class

The `Folder` class represents a directory and provides methods for directory operations:

```php
use Lucent\Filesystem\Folder;

// Create a Folder instance (path relative to root)
$folder = new Folder('storage/logs');

// Check if the folder exists
if (!$folder->exists()) {
    // Create it with permissions
    $folder->create(0755);
}

// Get all files in the folder (non-recursive by default)
$files = $folder->getFiles();

// Get all files recursively
$allFiles = $folder->getFiles(true);

// Search with filtering (returns a FileSystemCollection)
$collection = $folder->search();

// Delete the folder and all its contents
$folder->delete();
```

## Real-World Examples

### Example: Log File Management

Here's an example of a simple logging utility using the File system tools:

```php
<?php

namespace App\Utilities;

use Lucent\Facades\FileSystem;

class Logger
{
    private string $logPath;
    
    public function __construct(string $logName = 'application')
    {
        $this->logPath = 'storage/logs/' . $logName . '.log';
        
        // Ensure log file exists
        if (FileSystem::get($this->logPath) === null) {
            FileSystem::create($this->logPath);
        }
    }
    
    public function info(string $message): void
    {
        $this->log('INFO', $message);
    }
    
    public function error(string $message): void
    {
        $this->log('ERROR', $message);
    }
    
    public function warning(string $message): void
    {
        $this->log('WARNING', $message);
    }
    
    private function log(string $level, string $message): void
    {
        $logFile = FileSystem::get($this->logPath);
        if ($logFile !== null) {
            $entry = sprintf(
                "[%s] %s: %s%s",
                date('Y-m-d H:i:s'),
                $level,
                $message,
                PHP_EOL
            );
            // Append to the file using FILE_APPEND
            file_put_contents($logFile->path, $entry, FILE_APPEND);
        }
    }
    
    public function clear(): bool
    {
        $logFile = FileSystem::get($this->logPath);
        if ($logFile !== null) {
            return $logFile->write('');
        }
        return false;
    }
    
    public function getContents(): string
    {
        $logFile = FileSystem::get($this->logPath);
        return $logFile !== null ? $logFile->getContents() : '';
    }
}
```

### Example: File Upload Handling

Here's an example controller for handling file uploads:

```php
<?php

namespace App\Controllers;

use Lucent\Facades\FileSystem;
use Lucent\Http\Message\Response;
use Lucent\Http\Message\ServerRequest;

class FileUploadController
{
    private string $uploadDirectory = 'storage/uploads';
    
    public function upload(ServerRequest $request): Response
    {
        // Check if file was uploaded
        if (!isset($_FILES['file'])) {
            return (new Response())->withStatus(400);
        }
        
        $uploadedFile = $_FILES['file'];
        
        // Check for upload errors
        if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
            return (new Response())->withStatus(400);
        }
        
        // Generate a safe filename
        $filename = $this->getSafeFilename($uploadedFile['name']);
        $destination = $this->uploadDirectory . '/' . $filename;
        
        // Move the uploaded temp file to our destination
        if (!move_uploaded_file($uploadedFile['tmp_name'], FileSystem::rootPath() . '/' . $destination)) {
            return (new Response())->withStatus(500);
        }
        
        return Response::json([
            'file' => [
                'name' => $filename,
                'path' => $destination,
                'size' => filesize(FileSystem::rootPath() . '/' . $destination)
            ]
        ], 200);
    }
    
    private function getSafeFilename(string $filename): string
    {
        // Replace spaces with underscores
        $filename = str_replace(' ', '_', $filename);
        
        // Remove any characters that aren't alphanumeric, underscore, dash, or dot
        $filename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $filename);
        
        // Ensure filename uniqueness by adding a timestamp if file exists
        $pathInfo = pathinfo($filename);
        $baseFilename = $pathInfo['filename'];
        $extension = $pathInfo['extension'] ?? '';
        
        $fullPath = $this->uploadDirectory . '/' . $filename;
        $counter = 1;
        
        while (FileSystem::get($fullPath) !== null) {
            $filename = $baseFilename . '_' . time() . ($counter > 1 ? '_' . $counter : '');
            $filename .= $extension ? '.' . $extension : '';
            $fullPath = $this->uploadDirectory . '/' . $filename;
            $counter++;
        }
        
        return $filename;
    }
}
```

### Example: Configuration Files

Here's an example of a simple configuration manager:

```php
<?php

namespace App\Utilities;

use Lucent\Facades\FileSystem;

class ConfigManager
{
    private string $configPath;
    private array $config;
    
    public function __construct(string $configFile = 'config')
    {
        $this->configPath = 'storage/app/' . $configFile . '.json';
        $this->loadConfig();
    }
    
    private function loadConfig(): void
    {
        $configFile = FileSystem::get($this->configPath);
        
        if ($configFile === null) {
            // Config file doesn't exist, create it with default values
            $this->config = [
                'app_name' => 'Lucent Application',
                'debug' => false,
                'timezone' => 'UTC',
                'cache_enabled' => true
            ];
            
            $this->saveConfig();
        } else {
            // Load existing config
            $content = $configFile->getContents();
            $this->config = json_decode($content, true) ?? [];
        }
    }
    
    public function get(string $key, $default = null)
    {
        return $this->config[$key] ?? $default;
    }
    
    public function set(string $key, $value): void
    {
        $this->config[$key] = $value;
        $this->saveConfig();
    }
    
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->config);
    }
    
    public function all(): array
    {
        return $this->config;
    }
    
    private function saveConfig(): void
    {
        $configFile = FileSystem::get($this->configPath);
        
        if ($configFile === null) {
            $configFile = FileSystem::create($this->configPath, '');
        }
        
        $jsonContent = json_encode($this->config, JSON_PRETTY_PRINT);
        $configFile->write($jsonContent);
    }
}
```

## Best Practices

1. **Use Relative Paths**: When working with the FileSystem facade, use paths relative to the application root whenever possible, making your code more portable.

2. **Error Handling**: Always check the return values of file operations and handle potential errors gracefully. `FileSystem::get()` returns `null` if a file doesn't exist.

3. **Security Considerations**:
    - The FileSystem facade enforces a containment guard — paths that escape the configured root path are rejected.
    - Validate and sanitize filenames to prevent directory traversal attacks.
    - Be cautious with user-supplied paths.
    - Restrict file operations to designated directories.

4. **Path Consistency**: Use consistent path formats throughout your application, ideally using constants or configuration values for common directories.

5. **Directory Structure**: Follow a consistent directory structure for your application, such as:
    - `storage/app` - Application files
    - `storage/logs` - Log files
    - `storage/cache` - Cache files
    - `storage/uploads` - User uploads

6. **Permissions**: Ensure appropriate file permissions are set for security and functionality using `setPermissions()`.

7. **Large Files**: Be mindful of memory usage when working with large files; consider using streaming approaches for very large files.

8. **Fallback Strategies**: Implement fallback mechanisms for critical file operations to handle edge cases.

The Lucent file system tools provide a powerful and intuitive way to handle files in your application. By following these guidelines and leveraging the FileSystem facade, File class, and Folder class, you can write clean, maintainable code for your file operations.