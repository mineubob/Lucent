<?php

namespace Lucent\Commandline;

use Exception;
use Lucent\Commandline\Components\ProgressBar;
use Lucent\Facades\App;
use Lucent\Facades\FileSystem;
use Lucent\Http\HttpClient;
use ZipArchive;

class DeploymentController
{
    public static string $command_latest   = "deploy latest";
    public static string $command_rollback = "deploy rollback";

    private array $excludeFromBackup = [
        '.env',
        'vendor',
        'storage/backups',
    ];

    private array $excludeFromExtract = [
        '.env',
        'vendor',
        'storage',
    ];

    private array $excludeFromDelete = [
        '.env',
        'vendor',
        'storage/backups',
        'storage/temp',
        'logs',
    ];

    public function latest(): string
    {
        $url   = App::env('DEPLOY_URL');
        $token = App::env('DEPLOY_TOKEN');

        if (!$url) {
            return "No DEPLOY_URL set in .env" . PHP_EOL;
        }

        echo "Deploy URL: {$url}" . PHP_EOL;
        echo "Token: " . ($token ? substr($token, 0, 8) . "..." : "not set") . PHP_EOL;

        $client = new HttpClient();
        $client->withTimeout(120);
        $client->withCurlOption(CURLOPT_UNRESTRICTED_AUTH, true);
        $client->withCurlOption(CURLOPT_FOLLOWLOCATION, true);

        if ($token) {
            $client->withHeaders([
                'Authorization'        => "Bearer {$token}",
                'Accept'               => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
            ]);
        }

        echo "Downloading update..." . PHP_EOL;

        $zipName = 'project-update.zip';

        $estimatedSize = 10 * 1024 * 1024;
        $progress = new ProgressBar($estimatedSize);
        $progress->setFormat('[{bar}] {percent}% - {eta} remaining');
        $progress->setBarCharacters(['█', '░']);
        $progress->setUpdateInterval(0.1);

        $response = $client->download($url, $zipName, function ($downloaded, $total) use ($progress) {
            $progress->update($downloaded);
        });

        $progress->finish();

        if (!$response->successful()) {
            return "Failed to download update. HTTP status: " . $response->status() . PHP_EOL;
        }

        $zipPath = FileSystem::rootPath() . '/storage/downloads/' . $zipName;

        if (!file_exists($zipPath)) {
            return "Download appeared successful but zip not found at: {$zipPath}" . PHP_EOL;
        }

        echo "Downloaded " . round(filesize($zipPath) / 1024) . "KB" . PHP_EOL;

        echo "Backing up current project..." . PHP_EOL;

        $backupResult = $this->backup();
        if ($backupResult !== true) {
            return $backupResult;
        }

        echo "Applying update..." . PHP_EOL;

        $extractResult = $this->extract($zipPath, $this->excludeFromExtract);
        if ($extractResult !== true) {
            return $extractResult;
        }

        unlink($zipPath);

        return "Deployed successfully! 🎉" . PHP_EOL .
            "Run 'php cli deploy rollback' to revert if needed." . PHP_EOL;
    }

    public function rollback(): string
    {
        $backupsDir = FileSystem::rootPath() . '/storage/backups/';

        if (!is_dir($backupsDir)) {
            return "No backups found." . PHP_EOL;
        }

        $backups = array_filter(
            scandir($backupsDir),
            fn($d) => $d !== '.' && $d !== '..' && str_ends_with($d, '.zip')
        );

        if (empty($backups)) {
            return "No backups found." . PHP_EOL;
        }

        rsort($backups);
        $latestZip = $backupsDir . $backups[0];

        echo "Cleaning current project..." . PHP_EOL;

        $this->deleteDirectory(FileSystem::rootPath(), $this->excludeFromDelete);

        echo "Restoring from " . $backups[0] . "..." . PHP_EOL;

        $extractResult = $this->extract($latestZip, $this->excludeFromExtract);
        if ($extractResult !== true) {
            return $extractResult;
        }

        unlink($latestZip);

        return "Rolled back successfully! 🔙" . PHP_EOL;
    }

    private function backup(): true|string
    {
        $backupsDir = FileSystem::rootPath() . '/storage/backups/';
        $timestamp  = date('YmdHis');
        $backupPath = $backupsDir . $timestamp . '.zip';
        $root       = FileSystem::rootPath();

        if (!is_dir($backupsDir) && !mkdir($backupsDir, 0755, true)) {
            return "Failed to create backups directory." . PHP_EOL;
        }

        $zip = new ZipArchive();

        if ($zip->open($backupPath, ZipArchive::CREATE) !== true) {
            return "Failed to create backup zip." . PHP_EOL;
        }

        try {
            $this->addDirectoryToZip($zip, $root, $root, $this->excludeFromBackup);
        } catch (Exception $e) {
            $zip->close();
            return "Backup failed: " . $e->getMessage() . PHP_EOL;
        }

        $zip->close();
        return true;
    }

    private function addDirectoryToZip(ZipArchive $zip, string $src, string $root, array $exclude = []): void
    {
        $items = scandir($src);

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $srcPath  = $src . '/' . $item;
            $relative = substr($srcPath, strlen($root) + 1);

            foreach ($exclude as $ex) {
                if ($relative === $ex || str_starts_with($relative, $ex . '/')) {
                    continue 2;
                }
            }

            if (is_dir($srcPath)) {
                $zip->addEmptyDir($relative);
                $this->addDirectoryToZip($zip, $srcPath, $root, $exclude);
            } else {
                $zip->addFile($srcPath, $relative);
            }
        }
    }

    private function extract(string $zipPath, array $exclude): true|string
    {
        $zip = new ZipArchive();

        if ($zip->open($zipPath) !== true) {
            return "Failed to open zip file." . PHP_EOL;
        }

        $root = FileSystem::rootPath();

        // Detect leading folder prefix (e.g. GitHub zips: repo-main/)
        $prefix = '';
        $first  = $zip->getNameIndex(0);
        if ($first && str_ends_with($first, '/')) {
            $prefix = $first;
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);

            $relative = $prefix ? substr($name, strlen($prefix)) : $name;

            if ($relative === '' || $relative === false) {
                continue;
            }

            foreach ($exclude as $ex) {
                if ($relative === $ex || str_starts_with($relative, $ex . '/') || str_starts_with($relative, $ex)) {
                    continue 2;
                }
            }

            $target = $root . '/' . $relative;

            if (str_ends_with($name, '/')) {
                if (!is_dir($target)) {
                    mkdir($target, 0755, true);
                }
                continue;
            }

            $dir = dirname($target);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            file_put_contents($target, $zip->getFromIndex($i));
        }

        $zip->close();
        return true;
    }

    private function deleteDirectory(string $dir, array $exclude = []): void
    {
        $items = scandir($dir);
        $root  = FileSystem::rootPath();

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path     = $dir . '/' . $item;
            $relative = substr($path, strlen($root) + 1);

            foreach ($exclude as $ex) {
                if ($relative === $ex || str_starts_with($relative, $ex . '/')) {
                    continue 2;
                }
            }

            if (is_dir($path)) {
                $this->deleteDirectory($path, $exclude);
                if (count(scandir($path)) === 2) {
                    rmdir($path);
                }
            } else {
                unlink($path);
            }
        }
    }
}