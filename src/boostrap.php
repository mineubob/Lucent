<?php

use Lucent\Facades\FileSystem;

$pharPath = Phar::running(false);
$pharActive = !empty($pharPath);

// Load framework from .phar (PRODUCTION) or source (DEVELOPMENT/TESTS)
if ($pharActive) {
    define("ROOT", Phar::running() . DIRECTORY_SEPARATOR);
    $runningLocation = dirname($pharPath, 2) . DIRECTORY_SEPARATOR;
    $phar = new Phar($pharPath);
    $metadata = $phar->getMetadata();
    define("VERSION", $metadata['version'] ?? 'unknown');
} else {
    define("ROOT", __DIR__ . DIRECTORY_SEPARATOR);
    $runningLocation = dirname(__DIR__) . DIRECTORY_SEPARATOR . "temp_install" . DIRECTORY_SEPARATOR;
    define("VERSION", 'v0.' . date('ymd') . '.local');
}

// Define constants
define("RUNNING_LOCATION", $runningLocation);
define("LUCENT", ROOT . "Lucent" . DIRECTORY_SEPARATOR);

// Set file system root
require_once LUCENT . "Facades" . DIRECTORY_SEPARATOR . "FileSystem.php";
FileSystem::overrideRootPath(RUNNING_LOCATION);

define("PACKAGES_ROOT", FileSystem::rootPath() . "packages" . DIRECTORY_SEPARATOR);
define("APP", FileSystem::rootPath() . "App" . DIRECTORY_SEPARATOR);

// Include Composer autoloader if available
$composerAutoloader = PACKAGES_ROOT . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
if (file_exists($composerAutoloader)) {
    require_once $composerAutoloader;
}

// Import database constants
require_once LUCENT . "Database" . DIRECTORY_SEPARATOR . "constants.php";

// Register PSR-0 autoloader
spl_autoload_register(function ($class) use ($pharActive, $pharPath) {
    // Determine base path based on namespace
    if (str_starts_with($class, 'Lucent\\')) {
        $basePath = $pharActive ? "phar://$pharPath" : __DIR__;
    } else {
        $basePath = FileSystem::rootPath();
    }

    // Convert namespace to file path
    $file = str_replace('\\', DIRECTORY_SEPARATOR, $class) . '.php';
    $fullPath = rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $file;

    // Attempt to load the file
    if (file_exists($fullPath)) {
        require_once $fullPath;
        return true;
    }

    // Log error in development only
    if (!$pharActive) {
        error_log("Autoloader: File not found at: " . $fullPath);
    }

    return false;
});

// Class aliases for backwards compatibility
class_alias('Lucent\Model\Model', 'Lucent\Model');
class_alias('Lucent\Model\Collection', 'Lucent\ModelCollection');