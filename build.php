<?php

// build.php
if (ini_get('phar.readonly')) {
    ini_set('phar.readonly', 0);
}

// ANSI color codes for output
const COLORS = [
    'GREEN' => "\033[32m",
    'RED' => "\033[31m",
    'YELLOW' => "\033[33m",
    'BLUE' => "\033[34m",
    'RESET' => "\033[0m",
    'BOLD' => "\033[1m"
];

function log_step(string $message): void {
    echo COLORS['BLUE'] . "→ " . COLORS['RESET'] . $message . PHP_EOL;
}

function log_success(string $message): void {
    echo COLORS['GREEN'] . "✓ " . COLORS['RESET'] . $message . PHP_EOL;
}

function log_error(string $message): void {
    echo COLORS['RED'] . "✗ " . COLORS['RESET'] . $message . PHP_EOL;
}

function log_warning(string $message): void {
    echo COLORS['YELLOW'] . "! " . COLORS['RESET'] . $message . PHP_EOL;
}

function log_header(string $message): void {
    echo PHP_EOL . COLORS['BOLD'] . COLORS['BLUE'] . "=== " . $message . " ===" . COLORS['RESET'] . PHP_EOL;
}

// Start build process
log_header("Starting Lucent Framework Build Process");

$pharFile = 'lucent.phar';
$sourceDir = __DIR__ . DIRECTORY_SEPARATOR . "src";

// Verify source directory exists
if (!is_dir($sourceDir)) {
    log_error("Source directory not found: $sourceDir");
    exit(1);
}

// Clean up existing PHAR
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

// Create new PHAR
log_step("Creating new PHAR archive...");
try {
    $phar = new Phar($pharFile);
} catch (Exception $e) {
    log_error("Failed to create PHAR: " . $e->getMessage());
    exit(1);
}

// Count files to be added
$fileCount = iterator_count(
    new RegexIterator(
        new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceDir)
        ),
        '/\.(php|env)$/'
    )
);

log_step("Found $fileCount files to package");

// Start adding files
log_step("Adding files to PHAR...");
try {
    $phar->buildFromDirectory($sourceDir, '/\.(php|env)$/');
    log_success("Successfully added all files to PHAR");
} catch (Exception $e) {
    log_error("Failed to build PHAR: " . $e->getMessage());
    exit(1);
}

// Create and set stub
log_step("Creating PHAR stub...");
$stub = <<<'EOF'
<?php
Phar::mapPhar();

// Load the framework's autoloader and program
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

// Verify PHAR
log_step("Verifying PHAR file...");
try {
    $verify = new Phar($pharFile);
    $fileCount = count($verify);
    log_success("PHAR verification successful ($fileCount files)");
} catch (Exception $e) {
    log_error("PHAR verification failed: " . $e->getMessage());
    exit(1);
}

// Calculate file size
$fileSize = round(filesize($pharFile) / 1024, 2);
log_success("Build complete! PHAR size: {$fileSize}KB");