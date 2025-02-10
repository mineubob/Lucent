<?php
// local-build.php
$buildDir = __DIR__ . '/build/tmp';

// Create build directory if it doesn't exist
if (!is_dir($buildDir)) {
    mkdir($buildDir, 0777, true);
}

$version = 'v0.' . date('ymd')  . '.' . time();

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

    require_once $newPharFile;
}else {

    log_error("Fatal error, failed to build phar.\n");
    die;
}

