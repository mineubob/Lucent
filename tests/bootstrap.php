<?php

/**
 * PHPUnit bootstrap for Lucent.
 *
 * Composer's autoloader (vendor/autoload.php) loads the framework's
 * src/bootstrap.php automatically, which sets RUNNING_LOCATION to the
 * project root. For tests we need RUNNING_LOCATION to point at a
 * disposable temp_install/ directory so tests can write fixtures
 * (App/, routes/, commands/, storage/, .env) without polluting the
 * repo. This bootstrap overrides FileSystem::rootPath() to that end.
 *
 * Spawned child processes (e.g. ConsoleCommandTest::test_commandline_from_cli)
 * also load this file via require. They inherit the LUCENT_TEST_BOOTSTRAPPED
 * env var, so they skip the cleanup and fixture setup — they just need the
 * autoloader and the FileSystem override.
 */

use Lucent\Application;
use Lucent\Database;
use Lucent\Facades\FileSystem;
use Lucent\Facades\Log;
use Lucent\Logging\Channel;
use Lucent\Logging\Drivers\CliDriver;
use Lucent\Logging\Drivers\FileDriver;
use Lucent\Logging\Drivers\TeeDriver;

require_once __DIR__ . '/../vendor/autoload.php';

// Location of the disposable test working directory.
// Kept at the repo root (temp_install/) to match the path the test suite
// expects via __DIR__ . '/../../temp_install/'.
if (!defined('TEMP_ROOT')) {
    define('TEMP_ROOT', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'temp_install' . DIRECTORY_SEPARATOR);
}

// Only run cleanup + directory creation once (the main PHPUnit process).
// Spawned child processes inherit this env var and skip straight to the
// FileSystem override below.
$isMainProcess = !getenv('LUCENT_TEST_BOOTSTRAPPED');

if ($isMainProcess) {
    putenv('LUCENT_TEST_BOOTSTRAPPED=1');

    // Clean up any leftover files from a previous test run so each run starts
    // fresh. This prevents stale fixtures (models, routes, .env) from causing
    // false failures or masking bugs.
    if (is_dir(TEMP_ROOT)) {
        $rii = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(TEMP_ROOT, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($rii as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }
        rmdir(TEMP_ROOT);
    }

    // Ensure the temp_install directory exists with the structure tests expect.
    $dirs = [
        '',
        'App',
        'App/Commands',
        'App/Controllers',
        'App/Rules',
        'App/Models',
        'App/Views',
        'App/Extensions/Http',
        'routes',
        'commands',
        'storage',
        'storage/downloads',
        'storage/documentation',
        'storage/backups',
        'storage/temp',
        'logs',
    ];

    foreach ($dirs as $dir) {
        $path = TEMP_ROOT . $dir;
        if (!is_dir($path)) {
            @mkdir($path, 0755, true);
        }
    }
}

// Override the FileSystem root so the framework operates inside temp_install/.
FileSystem::overrideRootPath(TEMP_ROOT);

// Configure logging channels (mirrors the old dev_build.php setup).
// Only on the main PHPUnit process — spawned child processes would mix log
// output into the command output they're testing.
if ($isMainProcess) {
    $app = Application::getInstance();

    $app->addLoggingChannel(new Channel('phpunit', new TeeDriver(new CliDriver(), new FileDriver('phpunit.log')), false));
    $app->addLoggingChannel(new Channel('lucent.db', new TeeDriver(new CliDriver(), new FileDriver('db.log'))));
    $app->addLoggingChannel(new Channel('lucent.routing', new TeeDriver(new CliDriver(), new FileDriver('routing.log'))));
    $app->addLoggingChannel(new Channel('lucent.filesystem', new TeeDriver(new CliDriver(), new FileDriver('filesystem.log'))));
    $app->addLoggingChannel(new Channel('lucent.http', new TeeDriver(new CliDriver(), new FileDriver('http.log'))));
    $app->addLoggingChannel(new Channel('lucent.commandline', new TeeDriver(new CliDriver(), new FileDriver('commandline.log'))));

    // Wire the database logger to the lucent.db channel. Application::boot()
    // does this in production, but tests that use DatabaseTesting never call
    // boot(), so without this the DB logger stays null and Database::log()
    // silently drops every query. Database::reset() does not clear the logger,
    // so this persists across all setupDatabase() calls.
    Database::setLogger(Log::channel("lucent.db"));
}

// Register a PSR-4 autoloader for the user's App\ namespace pointing at the
// temp_install/ directory. In a real project this mapping lives in the
// project's composer.json; for tests we register it here so test fixtures
// written under temp_install/App/ can be autoloaded.
spl_autoload_register(function (string $class): bool {
    if (!str_starts_with($class, 'App\\')) {
        return false;
    }
    $relative = substr($class, 4); // strip "App\"
    $file = TEMP_ROOT . 'App' . DIRECTORY_SEPARATOR
        . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';
    if (file_exists($file)) {
        require_once $file;
        return true;
    }
    return false;
});
