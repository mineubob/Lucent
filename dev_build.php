<?php
// local-build.php
use Lucent\Application;
use Lucent\Database;
use Lucent\Logging\Channel;

cleanupDirectory(__DIR__ . '/temp_install');

$buildDir = __DIR__ . '/temp_install/packages';

// Create build directory if it doesn't exist
if (!is_dir($buildDir)) {
    mkdir($buildDir, 0777, true);
}

$version = 'v0.' . date('ymd')  . '.local';

const TEMP_ROOT = __DIR__ . DIRECTORY_SEPARATOR."temp_install".DIRECTORY_SEPARATOR;

// Define the original pharFile path and our new path
$originalPharFile = 'lucent.phar';
$newPharFile = $buildDir . '/lucent.phar';

// Run the original build script
require_once 'build.php';

// Now modify the phar after it's built
if (file_exists($originalPharFile)) {
    $phar = new Phar($originalPharFile);

    // Add our version metadata
    $phar->setMetadata(['version' => $version]);

    // Move the file to our build directory
    rename($originalPharFile, $newPharFile);

    log_success("Phar built successfully with version $version in $newPharFile\n");

    checkAndLoadEnviromentTestingVariables(__DIR__ . "/mysql-config.php");

    require_once $newPharFile;

    $app = Application::getInstance();

    $log = new Channel("phpunit","local_file","phpunit.log");

    $app->addLoggingChannel("phpunit",$log);

}else {

    log_error("Fatal error, failed to build phar.\n");
    die;
}


function cleanupDirectory(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $files = array_diff(scandir($dir), ['.', '..']);
    foreach ($files as $file) {
        $path = $dir . DIRECTORY_SEPARATOR . $file;
        is_dir($path) ? cleanupDirectory($path) : unlink($path);
    }
    rmdir($dir);
}

function checkAndLoadEnviromentTestingVariables(string $filePath) {
    if (!file_exists($filePath)) {
        return false;
    }

    $config = include $filePath;

    if (!is_array($config)) {
        return false;
    }

    foreach ($config as $key => $value) {
        if (is_bool($value)) {
            $value = $value ? 'true' : 'false';
        } elseif (is_null($value)) {
            $value = '';
        } elseif (is_array($value) || is_object($value)) {
            $value = json_encode($value);
        } else {
            $value = (string)$value;
        }

        putenv("$key=$value");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    return true;
}

