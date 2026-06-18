<?php

// build_orm.php
if (ini_get('phar.readonly')) {
    ini_set('phar.readonly', 0);
}

// ANSI color codes for output
const COLORS = [
    'GREEN'  => "\033[32m",
    'RED'    => "\033[31m",
    'YELLOW' => "\033[33m",
    'BLUE'   => "\033[34m",
    'RESET'  => "\033[0m",
    'BOLD'   => "\033[1m"
];

function log_step(string $message): void    { echo COLORS['BLUE']  . "→ " . COLORS['RESET'] . $message . PHP_EOL; }
function log_success(string $message): void { echo COLORS['GREEN'] . "✓ " . COLORS['RESET'] . $message . PHP_EOL; }
function log_error(string $message): void   { echo COLORS['RED']   . "✗ " . COLORS['RESET'] . $message . PHP_EOL; }
function log_warning(string $message): void { echo COLORS['YELLOW'] . "! " . COLORS['RESET'] . $message . PHP_EOL; }
function log_header(string $message): void  { echo PHP_EOL . COLORS['BOLD'] . COLORS['BLUE'] . "=== " . $message . " ===" . COLORS['RESET'] . PHP_EOL; }

log_header("Starting Lucent ORM Build Process");

$pharFile  = 'lucent-orm.phar';
$sourceDir = __DIR__ . DIRECTORY_SEPARATOR . 'src';

// Directories to include wholesale
$includeDirs = [
    'Lucent/Database',
    'Lucent/Model'
];

// Individual files to include
$includeFiles = [
    'Lucent/Database.php',
    'Lucent/Helpers/Reflection/TypedProperty.php'
];

// Verify source directory
if (!is_dir($sourceDir)) {
    log_error("Source directory not found: $sourceDir");
    exit(1);
}

// Clean up existing phar
if (file_exists($pharFile)) {
    log_step("Removing existing PHAR file...");
    try {
        unlink($pharFile);
        log_success("Removed existing PHAR file");
    } catch (Exception $e) {
        log_error("Failed to remove existing PHAR: " . $e->getMessage());
        exit(1);
    }
}

// Create new phar
log_step("Creating new PHAR archive...");
try {
    $phar = new Phar($pharFile);
} catch (Exception $e) {
    log_error("Failed to create PHAR: " . $e->getMessage());
    exit(1);
}

// Collect files from directories
$files = [];

foreach ($includeDirs as $dir) {
    $fullPath = $sourceDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $dir);

    if (!is_dir($fullPath)) {
        log_error("Expected directory not found: $fullPath");
        exit(1);
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($fullPath, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $relative = substr($file->getPathname(), strlen($sourceDir) + 1);
            // Normalise to forward slashes for phar paths
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
            $files[$relative] = $file->getPathname();
        }
    }
}

// Collect individual files
foreach ($includeFiles as $relative) {
    $fullPath = $sourceDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);

    if (!file_exists($fullPath)) {
        log_error("Expected file not found: $fullPath");
        exit(1);
    }

    $files[$relative] = $fullPath;
}

log_step("Found " . count($files) . " files to package");

// Add files to phar
log_step("Adding files to PHAR...");
try {
    $phar->buildFromIterator(new ArrayIterator($files));
    log_success("Successfully added " . count($files) . " files to PHAR");
} catch (Exception $e) {
    log_error("Failed to build PHAR: " . $e->getMessage());
    exit(1);
}

// Inject ORM-specific bootstrap (no Application, no HTTP/CLI setup)
log_step("Injecting ORM bootstrap...");
$ormBootstrap = <<<'BOOTSTRAP'
<?php

$pharPath   = Phar::running(false);
$pharActive = !empty($pharPath);

if ($pharActive) {
    define("ROOT", Phar::running() . DIRECTORY_SEPARATOR);
    define("RUNNING_LOCATION", dirname($pharPath, 2) . DIRECTORY_SEPARATOR);
} else {
    define("ROOT", __DIR__ . DIRECTORY_SEPARATOR);
    define("RUNNING_LOCATION", dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

define("LUCENT", ROOT . "Lucent" . DIRECTORY_SEPARATOR);

require_once LUCENT . "Database" . DIRECTORY_SEPARATOR . "constants.php";

spl_autoload_register(function (string $class) use ($pharActive, $pharPath): bool {
    if (str_starts_with($class, 'Lucent\\')) {
        $basePath = $pharActive ? "phar://$pharPath" : __DIR__;
        $file     = str_replace('\\', DIRECTORY_SEPARATOR, $class) . '.php';
        $fullPath = rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $file;

        if (file_exists($fullPath)) {
            require_once $fullPath;
            return true;
        }
    }

    return false;
});

class_alias('Lucent\Model\Model',       'Lucent\Model');
class_alias('Lucent\Model\Collection',  'Lucent\ModelCollection');
BOOTSTRAP;

try {
    $phar->addFromString('boostrap.php', $ormBootstrap);
    log_success("ORM bootstrap injected");
} catch (Exception $e) {
    log_error("Failed to inject bootstrap: " . $e->getMessage());
    exit(1);
}

// Stub — just maps the phar and loads the bootstrap, no Application or CLI
log_step("Creating PHAR stub...");
$stub = <<<'EOF'
<?php
Phar::mapPhar();

require 'phar://' . __FILE__ . '/boostrap.php';

__HALT_COMPILER();
EOF;

try {
    $phar->setStub($stub);
    log_success("Successfully set PHAR stub");
} catch (Exception $e) {
    log_error("Failed to set PHAR stub: " . $e->getMessage());
    exit(1);
}

// Force flush before verify
unset($phar);

// Verify
log_step("Verifying PHAR file...");
try {
    $verify   = new Phar($pharFile);
    $count    = count($verify);
    $size     = round(filesize($pharFile) / 1024, 2);
    log_success("PHAR verification successful ($count files, {$size}KB)");
} catch (Exception $e) {
    log_error("PHAR verification failed: " . $e->getMessage());
    exit(1);
}

log_header("Lucent ORM Build Complete → $pharFile");