<?php

if (Phar::running()) {
    define("ROOT", Phar::running() . "/");
    define("EXTERNAL_ROOT", getcwd() . DIRECTORY_SEPARATOR);
} else {
    define("ROOT", getcwd() . DIRECTORY_SEPARATOR);
    define("EXTERNAL_ROOT", ROOT);
}

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