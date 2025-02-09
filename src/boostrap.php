<?php

if (Phar::running()) {
    define("ROOT", Phar::running() . "/");

    $pharPath = Phar::running(false);
    $runningLocation = dirname($pharPath,2);
     define("EXTERNAL_ROOT",$runningLocation . DIRECTORY_SEPARATOR);

} else {
    define("ROOT", getcwd() . DIRECTORY_SEPARATOR);
    define("EXTERNAL_ROOT", dirname(ROOT));
}

// Define the path to packages directory
if (!defined('PACKAGES_ROOT')) {
    define('PACKAGES_ROOT', EXTERNAL_ROOT . 'packages' . DIRECTORY_SEPARATOR);
}

// Check for Composer's autoloader in packages directory
$composerAutoloader = PACKAGES_ROOT . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

if (file_exists($composerAutoloader)) {
    require_once $composerAutoloader;
}

const APP =  ROOT . 'App'.DIRECTORY_SEPARATOR;
const FRAMEWORK  = APP . 'Framework'.DIRECTORY_SEPARATOR;
const CONTROLLERS = APP.'Controllers'.DIRECTORY_SEPARATOR;
const MODELS = APP.'Models'.DIRECTORY_SEPARATOR;
const ROUTES = ROOT .'Routes'.DIRECTORY_SEPARATOR;
const FACADES = APP .'Facades'.DIRECTORY_SEPARATOR;
const VIEWS = APP .'Views'.DIRECTORY_SEPARATOR;
const MIDDLEWARE = APP.'Middleware'.DIRECTORY_SEPARATOR;
const SERVICES = APP .'Services'.DIRECTORY_SEPARATOR;
const OBJECTS = APP .'ValueObjects'.DIRECTORY_SEPARATOR;
const STORAGE = ROOT .'Storage'.DIRECTORY_SEPARATOR;

const LUCENT  = ROOT . 'Lucent'.DIRECTORY_SEPARATOR;

$modules = [LUCENT,EXTERNAL_ROOT];

set_include_path(get_include_path().PATH_SEPARATOR.implode(PATH_SEPARATOR,$modules));

spl_autoload_register(function ($class) {
    // Convert namespace to path
    $file = str_replace('\\', '/', $class) . '.php';

    $pharPath = \Phar::running(false);

    if(str_starts_with($file, 'Lucent')) {
        $basePath = $pharPath ? "phar://$pharPath" : __DIR__;
    }else{
        $basePath = EXTERNAL_ROOT;
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