<?php
// local-build.php
use Lucent\Application;
use Lucent\Logging\Channel;
use Lucent\Logging\Drivers\CliDriver;
use Lucent\Logging\Drivers\FileDriver;
use Lucent\Logging\Drivers\TeeDriver;

cleanupDirectory(__DIR__ . '/temp_install');

$buildDir = __DIR__ . '/temp_install/packages';

// Create build directory if it doesn't exist
if (!is_dir($buildDir)) {
    mkdir($buildDir, 0777, true);
}

$version = 'v0.' . date('ymd') . '.local';

const TEMP_ROOT = __DIR__ . DIRECTORY_SEPARATOR . "temp_install" . DIRECTORY_SEPARATOR;

$files = copyDevClasses();


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


    if (getenv('XDEBUG_MODE') === 'coverage') {
        require_once $newPharFile;
    }else{
        require_once __DIR__.DIRECTORY_SEPARATOR."src".DIRECTORY_SEPARATOR."boostrap.php";
    }

    $app = Application::getInstance();

    $phpunitLog = new Channel("phpunit", new TeeDriver(new CliDriver(), new FileDriver("phpunit.log")), false);
    $app->addLoggingChannel("phpunit", $phpunitLog);

    $newDbLog= new Channel("lucent.db", new TeeDriver(new CliDriver(), new FileDriver("db.log")));
    $app->addLoggingChannel("lucent.db", $newDbLog);

    $routing = new Channel("lucent.routing", new TeeDriver(new CliDriver(), new FileDriver("routing.log")));
    $app->addLoggingChannel("lucent.routing", $routing);

    $fileSystem = new Channel("lucent.filesystem", new TeeDriver(new CliDriver(), new FileDriver("filesystem.log")));
    $app->addLoggingChannel("lucent.filesystem", $fileSystem);

    createFiles();

    foreach ($files as $path) {
        unlink($path);
    }

} else {

    foreach ($files as $path) {
        unlink($path);
    }
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

function checkAndLoadEnviromentTestingVariables(string $filePath): bool
{
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
            $value = (string) $value;
        }

        putenv("$key=$value");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    return true;
}

function copyDevClasses(): array
{

    $dev_folder = __DIR__ . DIRECTORY_SEPARATOR . "dev_classes" . DIRECTORY_SEPARATOR;
    $files = array_diff(scandir($dev_folder), array('.', '..'));
    $paths = [];

    foreach ($files as $file) {
        $path = __DIR__ . DIRECTORY_SEPARATOR . "src" . DIRECTORY_SEPARATOR . "Lucent" . DIRECTORY_SEPARATOR . $file;
        $paths[] = $path;
        file_put_contents($path, file_get_contents($dev_folder . $file));
    }

    return $paths;
}

function createFiles(): void
{
    $cli = <<<'PHP'
#!/usr/bin/env php
<?php
use Lucent\Application;
use Lucent\Facades\CommandLine;

$_SERVER["REQUEST_METHOD"] = "CLI";

require_once 'packages/lucent.phar';

$app = Application::getInstance();

// EXAMPLE
//CommandLine::register("test run", "run", TestCommand::class);

echo $app->executeConsoleCommand();
PHP;

    file_put_contents(TEMP_ROOT . "cli", $cli);
}

