[Home](../../README.md)

# File System Operations in Lucent

## Introduction

The Lucent Framework provides a powerful and intuitive API for working with files through the `File` facade and the `File` class. These tools offer a clean abstraction for common file system operations, making it easy to create, read, update, and delete files in your application.

## Table of Contents

- [Overview](#overview)
- [The File Facade](#the-file-facade)
    - [Root Path Management](#root-path-management)
    - [Getting Files](#getting-files)
    - [Retrieving a File](#retrieving-a-file)
    - [Creating Files](#creating-files)
- [The File Class](#the-file-class)
    - [File Properties](#file-properties)
    - [File Operations](#file-operations)
        - [Getting File Contents](#getting-file-contents)
        - [Getting File Size](#getting-file-size)
        - [Checking if a File Exists](#checking-if-a-file-exists)
        - [Deleting a File](#deleting-a-file)
        - [Appending to a File](#appending-to-a-file)
        - [Writing to a File](#writing-to-a-file)
        - [Renaming a File](#renaming-a-file)
        - [Copying a File](#copying-a-file)
        - [Moving a File](#moving-a-file)
- [Real-World Examples](#real-world-examples)
    - [Example: Log File Management](#example-log-file-management)
    - [Example: File Upload Handling](#example-file-upload-handling)
    - [Example: Configuration Files](#example-configuration-files)
- [Best Practices](#best-practices)

## Overview

The Lucent file system tools provide a simple but powerful abstraction around PHP's native file handling functions. The functionality is divided into two main components:

1. **File Facade**: A static interface for file system operations that don't require an instance.
2. **File Class**: An object-oriented interface for working with individual files.

Together, these components provide a comprehensive solution for file management in your Lucent applications.

## The File Facade

The `File` facade in Lucent provides static methods for working with the file system, focusing on operations that don't require an instance such as obtaining file lists, retrieving file instances, and creating new files.

### Root Path Management

The `File` facade uses a root path as the base directory for all relative file operations. By default, this is set to your application's root directory.

```php
use Lucent\Facades\File;

// Get the current root path
$rootPath = File::rootPath();
```

### Getting Files

To retrieve all files in a directory (recursively):

```php
use Lucent\Facades\File;

// Get all files in a specific directory (relative to root path)
$files = File::getFiles('storage/logs');

// Get all files in the root directory
$allFiles = File::getFiles();
```

The `getFiles()` method returns an array of `File` objects for each file in the directory, allowing you to perform operations on them:

```php
foreach (File::getFiles('storage/logs') as $file) {
    echo $file->path . ': ' . $file->getSize() . ' bytes' . PHP_EOL;
}
```

### Retrieving a File

To get a specific file:

```php
use Lucent\Facades\File;

// Get a file by path (relative to root path)
$file = File::get('storage/app/config.json');

// File::get() returns null if the file doesn't exist
if ($file !== null) {
    $contents = $file->getContents();
    // Process file contents...
}
```

### Creating Files

To create a new file:

```php
use Lucent\Facades\File;

// Create an empty file
$file = File::create('storage/app/newfile.txt');

// Create a file with initial content
$file = File::create('storage/app/data.json', '{"status": "active"}');
```

The `create()` method automatically creates any required directories in the path and returns a `File` object for further operations.

## The File Class

The `File` class provides an object-oriented interface for working with individual files. It encapsulates file operations like reading, writing, deleting, and moving files.

### File Properties

The `File` class has the following properties:

| Property | Description | Access |
|----------|-------------|--------|
| `path` | The absolute path to the file | Protected (readable) |

### File Operations

#### Getting File Contents

To retrieve the contents of a file:

```php
use Lucent\Facades\File;

$file = File::get('storage/app/config.json');
if ($file !== null) {
    $content = $file->getContents();
    // Process content...
}
```

The `getContents()` method returns the file contents as a string, or an empty string if the file doesn't exist.

#### Getting File Size

To get the size of a file in bytes:

```php
use Lucent\Facades\File;

$file = File::get('storage/logs/application.log');
if ($file !== null) {
    $size = $file->getSize();
    echo "Log file size: {$size} bytes";
}
```

The `getSize()` method returns the file size in bytes, or 0 if the file doesn't exist.

#### Checking if a File Exists

To check if a file exists:

```php
use Lucent\Facades\File;

$file = File::get('storage/app/config.json');
if ($file !== null && $file->exists()) {
    // File exists, proceed...
}
```

The `exists()` method returns a boolean indicating whether the file exists on the file system.

#### Deleting a File

To delete a file:

```php
use Lucent\Facades\File;

$file = File::get('storage/temp/cache.tmp');
if ($file !== null) {
    $result = $file->delete();
    if ($result) {
        // File was successfully deleted
    }
}
```

The `delete()` method returns a boolean indicating whether the deletion was successful.

#### Appending to a File

To append content to a file:

```php
use Lucent\Facades\File;

$logFile = File::get('storage/logs/application.log');
if ($logFile !== null) {
    $logEntry = date('[Y-m-d H:i:s]') . ' Info: Application started' . PHP_EOL;
    $logFile->append($logEntry);
}
```

The `append()` method returns a boolean indicating whether the operation was successful.

#### Writing to a File

To write content to a file (replacing existing content):

```php
use Lucent\Facades\File;

$configFile = File::get('storage/app/config.json');
if ($configFile !== null) {
    $newConfig = json_encode(['debug' => true, 'cache' => false], JSON_PRETTY_PRINT);
    $configFile->write($newConfig);
}
```

The `write()` method also accepts an optional second parameter to append rather than overwrite:

```php
// Append content (same as using append())
$file->write('New content to append', true);
```

The `write()` method returns a boolean indicating whether the operation was successful.

#### Renaming a File

To rename a file:

```php
use Lucent\Facades\File;

$file = File::get('storage/app/old-name.txt');
if ($file !== null) {
    // Rename the file (path is relative to root path)
    $file->rename('storage/app/new-name.txt');
    
    // Or provide an absolute path
    $file->rename('/var/www/absolute/path/new-name.txt', true);
}
```

The `rename()` method returns a boolean indicating whether the operation was successful. Note that the file's `path` property will be updated to the new path if the rename is successful.

#### Copying a File

To copy a file to a new location:

```php
use Lucent\Facades\File;

$file = File::get('storage/app/template.html');
if ($file !== null) {
    // Copy the file (destination is relative to root path)
    $file->copy('storage/app/copies/template-copy.html');
    
    // Or provide an absolute destination path
    $file->copy('/var/www/html/template-copy.html', true);
}
```

The `copy()` method returns a boolean indicating whether the copy operation was successful.

#### Moving a File

To move a file to a new location:

```php
use Lucent\Facades\File;

$file = File::get('storage/temp/upload.tmp');
if ($file !== null) {
    // Move the file (destination is relative to root path)
    $file->move('storage/app/uploads/file.ext');
    
    // Or provide an absolute destination path
    $file->move('/var/www/uploads/file.ext', true);
}
```

The `move()` method creates the destination directory if it doesn't exist and returns a boolean indicating whether the move operation was successful. Note that the file's `path` property will be updated to the new path if the move is successful.

## Real-World Examples

### Example: Log File Management

Here's an example of a simple logging utility using the File system tools:

```php
<?php

namespace App\Utilities;

use Lucent\Facades\File; // Import the File facade
use Lucent\Filesystem\File as FileClass; // Import the underlying File class

class Logger
{
    private string $logPath;
    
    public function __construct(string $logName = 'application')
    {
        $this->logPath = 'storage/logs/' . $logName . '.log';
        
        // Ensure log file exists
        if (File::get($this->logPath) === null) {
            File::create($this->logPath);
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
        $logFile = File::get($this->logPath);
        if ($logFile !== null) {
            $entry = sprintf(
                "[%s] %s: %s%s",
                date('Y-m-d H:i:s'),
                $level,
                $message,
                PHP_EOL
            );
            $logFile->append($entry);
        }
    }
    
    public function clear(): bool
    {
        $logFile = File::get($this->logPath);
        if ($logFile !== null) {
            return $logFile->write('');
        }
        return false;
    }
    
    public function getContents(): string
    {
        $logFile = File::get($this->logPath);
        return $logFile !== null ? $logFile->getContents() : '';
    }
}
```

### Example: File Upload Handling

Here's an example controller for handling file uploads:

```php
<?php

namespace App\Controllers;

use Lucent\Facades\File; // Import the File facade
use Lucent\Filesystem\File as FileClass; // Import the underlying File class
use Lucent\Http\Request;
use Lucent\Http\JsonResponse;

class FileUploadController
{
    private string $uploadDirectory = 'storage/uploads';
    
    public function upload(Request $request): JsonResponse
    {
        // Check if file was uploaded
        if (!isset($_FILES['file'])) {
            return new JsonResponse()
                ->setOutcome(false)
                ->setStatusCode(400)
                ->setMessage("No file was uploaded");
        }
        
        $uploadedFile = $_FILES['file'];
        
        // Check for upload errors
        if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
            return new JsonResponse()
                ->setOutcome(false)
                ->setStatusCode(400)
                ->setMessage("Upload failed with error code " . $uploadedFile['error']);
        }
        
        // Generate a safe filename
        $filename = $this->getSafeFilename($uploadedFile['name']);
        $destination = $this->uploadDirectory . '/' . $filename;
        
        // Move the uploaded temp file to our destination
        if (!move_uploaded_file($uploadedFile['tmp_name'], File::rootPath() . $destination)) {
            return new JsonResponse()
                ->setOutcome(false)
                ->setStatusCode(500)
                ->setMessage("Failed to save uploaded file");
        }
        
        return new JsonResponse()
            ->setOutcome(true)
            ->setMessage("File uploaded successfully")
            ->addContent('file', [
                'name' => $filename,
                'path' => $destination,
                'size' => File::get($destination)->getSize()
            ]);
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
        
        while (File::get($fullPath) !== null) {
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

use Lucent\Facades\File; // Import the File facade
use Lucent\Filesystem\File as FileClass; // Import the underlying File class

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
        $configFile = File::get($this->configPath);
        
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
        $configFile = File::get($this->configPath);
        
        if ($configFile === null) {
            $configFile = File::create($this->configPath, '');
        }
        
        $jsonContent = json_encode($this->config, JSON_PRETTY_PRINT);
        $configFile->write($jsonContent);
    }
}
```

## Best Practices

1. **Use Relative Paths**: When working with the File facade, use paths relative to the application root whenever possible, making your code more portable.

2. **Error Handling**: Always check the return values of file operations and handle potential errors gracefully.

3. **Security Considerations**:
    - Validate and sanitize filenames and paths to prevent directory traversal attacks
    - Be cautious with user-supplied paths
    - Restrict file operations to designated directories

4. **Resource Management**: Close or release file resources as soon as they're no longer needed.

5. **Path Consistency**: Use consistent path formats throughout your application, ideally using constants or configuration values for common directories.

6. **Transactions**: For critical file operations, consider implementing a transactional approach where you create backup copies or temporary files until operations are complete.

7. **Directory Structure**: Follow a consistent directory structure for your application, such as:
    - `storage/app` - Application files
    - `storage/logs` - Log files
    - `storage/cache` - Cache files
    - `storage/uploads` - User uploads

8. **Permissions**: Ensure appropriate file permissions are set for security and functionality.

9. **Large Files**: Be mindful of memory usage when working with large files; consider using streaming approaches for very large files.

10. **Fallback Strategies**: Implement fallback mechanisms for critical file operations to handle edge cases.

The Lucent file system tools provide a powerful and intuitive way to handle files in your application. By following these guidelines and leveraging the File facade and File class, you can write clean, maintainable code for your file operations.