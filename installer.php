<?php

// Base directory structure
$directories = [
    'App',
    'logs',
    'storage',
    'routes',
    'packages',
    'public',
    'storage',
];

// Files to create
$files = [
    'public/index.php' => <<<'PHP'
<?php

use Lucent\Facades\App;

require_once '../packages/lucent.phar';

App::RegisterRoutes("routes/api.php");

echo App::Execute();
PHP,
    'cli' => <<<'PHP'
#!/usr/bin/env php
<?php
use Lucent\Application;
use Lucent\Facades\CommandLine;
use App\Commands\TestCommand;

$_SERVER["REQUEST_METHOD"] = "CLI";

require_once 'packages/lucent.phar';

$app = Application::getInstance();

CommandLine::register("test run", "run", TestCommand::class);

echo $app->executeConsoleCommand();
PHP,
    'routes/api.php' => <<<'PHP'
<?php

use Lucent\Facades\Route;

// Define your API routes here
Route::rest()->group('api')
    ->prefix('api')
    ->defaultController(\App\Controllers\ApiController::class);
PHP,
    '.env' => <<<'ENV'
DB_USERNAME=root
DB_PASSWORD=
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=lucent
ENV
];

// Function to create directories
function createDirectories($dirs) {
    foreach ($dirs as $dir) {
        if (!file_exists($dir)) {
            if (mkdir($dir, 0755, true)) {
                echo "✅ Created directory: $dir\n";
            } else {
                echo "❌ Failed to create directory: $dir\n";
                return false;
            }
        } else {
            echo "ℹ️ Directory already exists: $dir\n";
        }
    }
    return true;
}

// Function to create files
function createFiles($files) {
    foreach ($files as $path => $content) {
        if (!file_exists($path)) {
            if (file_put_contents($path, $content) !== false) {
                echo "✅ Created file: $path\n";
                if ($path === 'lucent') {
                    chmod($path, 0755); // Make CLI file executable
                }
            } else {
                echo "❌ Failed to create file: $path\n";
                return false;
            }
        } else {
            echo "ℹ️ File already exists: $path\n";
        }
    }
    return true;
}

// Function to download latest Lucent PHAR
function downloadLatestLucent() {
    echo "📦 Downloading latest version of Lucent...\n";

    // Get latest release info from GitHub
    $ch = curl_init('https://api.github.com/repos/jackharrispeninsulainteractive/Lucent/releases/latest');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Lucent-Installer');
    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        echo "❌ Failed to check latest version: " . curl_error($ch) . "\n";
        curl_close($ch);
        return false;
    }
    curl_close($ch);

    $release = json_decode($response, true);
    if (!isset($release['assets'][0]['browser_download_url'])) {
        echo "❌ Failed to get download URL from GitHub\n";
        return false;
    }

    $downloadUrl = $release['assets'][0]['browser_download_url'];
    $version = $release['tag_name'];

    echo "ℹ️ Found version: {$version}\n";

    // Download the PHAR file
    $ch = curl_init($downloadUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $pharContent = curl_exec($ch);

    if (curl_errno($ch)) {
        echo "❌ Failed to download PHAR: " . curl_error($ch) . "\n";
        curl_close($ch);
        return false;
    }
    curl_close($ch);

    // Ensure packages directory exists
    if (!file_exists('packages')) {
        mkdir('packages', 0755, true);
    }

    // Save the PHAR file
    $pharPath = 'packages/lucent.phar';
    if (file_put_contents($pharPath, $pharContent) === false) {
        echo "❌ Failed to save PHAR file\n";
        return false;
    }

    // Make the PHAR executable
    chmod($pharPath, 0755);

    echo "✅ Downloaded and installed Lucent version {$version}\n";
    return true;
}

// Main installation process
echo "🚀 Starting Lucent installation...\n\n";

if (!createDirectories($directories)) {
    die("❌ Installation failed: Could not create directories\n");
}

if (!createFiles($files)) {
    die("❌ Installation failed: Could not create files\n");
}

if (!downloadLatestLucent()) {
    die("❌ Installation failed: Could not download Lucent\n");
}

echo "\n✨ Lucent installation completed successfully!\n";
echo "🌟 To get started, run: cd public; php -S localhost:8080 index.php\n";