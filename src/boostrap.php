<?php

use Lucent\Facades\FileSystem;

define("ROOT", Phar::running() . "/");

$pharPath = Phar::running(false);
$runningLocation = dirname($pharPath, 2);

define("RUNNING_LOCATION", $runningLocation . DIRECTORY_SEPARATOR);
const LUCENT = ROOT . "Lucent" . DIRECTORY_SEPARATOR;

require_once LUCENT . "Facades" . DIRECTORY_SEPARATOR . "FileSystem.php";

FileSystem::overrideRootPath(RUNNING_LOCATION);

define("PACKAGES_ROOT", FileSystem::rootPath() . 'packages' . DIRECTORY_SEPARATOR);

// Check for Composer's autoloader in packages directory
$composerAutoloader = PACKAGES_ROOT . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

if (file_exists($composerAutoloader)) {
    require_once $composerAutoloader;
}

define("APP", FileSystem::rootPath() . DIRECTORY_SEPARATOR . 'App' . DIRECTORY_SEPARATOR);
define("CONTROLLERS", FileSystem::rootPath() . DIRECTORY_SEPARATOR . "App" . DIRECTORY_SEPARATOR . 'Controllers' . DIRECTORY_SEPARATOR);

$modules = [LUCENT, FileSystem::rootPath()];

set_include_path(get_include_path() . PATH_SEPARATOR . implode(PATH_SEPARATOR, $modules));

spl_autoload_register(function ($class) {
    // Convert namespace to path
    $file = str_replace('\\', DIRECTORY_SEPARATOR, $class) . '.php';

    $pharPath = Phar::running(false);

    if (str_starts_with($file, 'Lucent')) {
        $basePath = $pharPath ? "phar://$pharPath" : __DIR__;
    } else {
        $basePath = FileSystem::rootPath();
    }

    // Full path to target file
    $fullPath = rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $file;

    if (file_exists($fullPath)) {
        require_once $fullPath;
        return true;
    }

    error_log("File not found at: " . $fullPath);
    return false;
});

require_once ROOT . "Lucent" . DIRECTORY_SEPARATOR . "Database" . DIRECTORY_SEPARATOR . "constants.php";

class_alias('Lucent\Model\Model', 'Lucent\Model');
class_alias('Lucent\Model\Collection', 'Lucent\ModelCollection');