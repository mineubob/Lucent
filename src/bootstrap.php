<?php

use Lucent\Facades\FileSystem;

/**
 * Lucent framework bootstrap.
 *
 * Composer-only: no PHAR support. This file is autoloaded via the
 * package's composer.json "autoload.files" entry, so requiring the
 * package's autoloader is enough to boot the framework constants.
 */

// Package source directory (vendor/blueprintau/lucent/src in a consumer,
// or this repo's src/ during development).
define("ROOT", __DIR__ . DIRECTORY_SEPARATOR);

// Project root: find the vendor directory from Composer's ClassLoader,
// then go one level up. This works regardless of the vendor-dir name or
// package path structure. Falls back to the repo root for development.
$projectRoot = null;
if (class_exists(\Composer\Autoload\ClassLoader::class)) {
    $loaders = \Composer\Autoload\ClassLoader::getRegisteredLoaders();
    $vendorDir = key($loaders);
    if ($vendorDir !== null && is_dir($vendorDir)) {
        // vendorDir is <project>/vendor — project root is its parent.
        $projectRoot = dirname($vendorDir);
    }
}
if ($projectRoot === null) {
    // Fallback: running from the Lucent repo itself (src/ is 1 level below repo root).
    $projectRoot = dirname(__DIR__, 1);
}

define("RUNNING_LOCATION", $projectRoot . DIRECTORY_SEPARATOR);

// Version: read from Composer's InstalledVersions (always available
// since this file is autoloaded via Composer's files entry).
define("VERSION", \Composer\InstalledVersions::getVersion('blueprintau/lucent') ?: 'unknown');

define("LUCENT", ROOT . "Lucent" . DIRECTORY_SEPARATOR);

// Set file system root
require_once LUCENT . "Facades" . DIRECTORY_SEPARATOR . "FileSystem.php";
FileSystem::overrideRootPath(RUNNING_LOCATION);

define("APP", FileSystem::rootPath() . DIRECTORY_SEPARATOR . "App" . DIRECTORY_SEPARATOR);


