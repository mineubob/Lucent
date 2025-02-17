<?php

use Lucent\Facades\File;

define("ROOT", Phar::running() . "/");

$pharPath = Phar::running(false);
$runningLocation = dirname($pharPath,2);

define("RUNNING_LOCATION", $runningLocation . DIRECTORY_SEPARATOR);
const LUCENT = ROOT . "Lucent" . DIRECTORY_SEPARATOR;

require_once LUCENT . "Facades".DIRECTORY_SEPARATOR."File.php";

File::overrideRootPath(RUNNING_LOCATION);

define("PACKAGES_ROOT", File::rootPath() . 'packages' . DIRECTORY_SEPARATOR);

// Check for Composer's autoloader in packages directory
$composerAutoloader = PACKAGES_ROOT . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

if (file_exists($composerAutoloader)) {
    require_once $composerAutoloader;
}

define("APP", File::rootPath() . 'App' . DIRECTORY_SEPARATOR);
define("CONTROLLERS", File::rootPath() . "app" . DIRECTORY_SEPARATOR . 'controllers' . DIRECTORY_SEPARATOR);

$modules = [LUCENT,File::rootPath()];

set_include_path(get_include_path().PATH_SEPARATOR.implode(PATH_SEPARATOR,$modules));

spl_autoload_register(function ($class) {
    // Convert namespace to path
    $file = str_replace('\\', '/', $class) . '.php';

    $pharPath = \Phar::running(false);

    if(str_starts_with($file, 'Lucent')) {
        $basePath = $pharPath ? "phar://$pharPath" : __DIR__;
    }else{
        $basePath = File::rootPath();
    }

    // Full path to target file
    $fullPath = $basePath . '/' . $file;

    if (file_exists($fullPath)) {
        require_once $fullPath;
        return true;
    }

    error_log("File not found at: " . $fullPath);
    return false;
});

require_once ROOT ."Lucent".DIRECTORY_SEPARATOR. "Database" . DIRECTORY_SEPARATOR . "constants.php";