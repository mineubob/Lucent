<?php

namespace Lucent\Commandline;

use Exception;
use Lucent\Commandline\Components\ProgressBar;
use Lucent\Facades\App;
use Lucent\Facades\FileSystem;
use Lucent\Http\Client\Client;
use Psr\Http\Client\ClientExceptionInterface;
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

        $headers = [];
        if ($token) {
            $headers = [
                'Authorization'        => "Bearer {$token}",
                'Accept'               => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
            ];
        }

        echo "Downloading update..." . PHP_EOL;

        $zipName = 'project-update.zip';
        $zipPath = FileSystem::rootPath() . '/storage/downloads/' . $zipName;

        $estimatedSize = 10 * 1024 * 1024;
        $progress = new ProgressBar($estimatedSize);
        $progress->setFormat('[{bar}] {percent}% - {eta} remaining');
        $progress->setBarCharacters(['█', '░']);
        $progress->setUpdateInterval(0.1);

        $client = new Client([
            'timeout'     => 120,
            'curl_options'=> [
                // CURLOPT_UNRESTRICTED_AUTH is deliberately NOT set: with it
                // unset, libcurl strips the Authorization header on
                // cross-host redirects, so the deploy token is not leaked to
                // a redirect target.
                CURLOPT_FOLLOWLOCATION    => true,
            ],
        ]);

        try {
            $response = $client->get($url, [], [
                'headers'  => $headers,
                'sink'     => $zipPath,
                'progress' => function ($downloaded, $total) use ($progress) {
                    $progress->update($downloaded);
                },
            ]);
        } catch (ClientExceptionInterface $e) {
            return "Failed to download update: " . $e->getMessage() . PHP_EOL;
        }

        $progress->finish();

        if ($response->getStatusCode() !== 200) {
            return "Failed to download update. HTTP status: " . $response->getStatusCode() . PHP_EOL;
        }

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

        $realRoot = realpath($root);

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

            // Zip-slip guard: reject any entry that escapes the project root.
            // A crafted zip can name entries `../evil.php` or `../../etc/x`;
            // without this check they would be written outside the root.
            if (
                str_contains($relative, '..')
                || str_starts_with($relative, '/')
                || str_contains($relative, '\\')
                || preg_match('/^[a-zA-Z]:/', $relative)
            ) {
                $zip->close();
                return "Refusing to extract entry with unsafe path: $relative" . PHP_EOL;
            }

            $target = $root . '/' . $relative;

            // Create the parent directory (or the directory entry itself)
            // before the containment check, so realpath() can resolve it.
            if (str_ends_with($name, '/')) {
                if (!is_dir($target)) {
                    mkdir($target, 0755, true);
                }
            } else {
                $dir = dirname($target);
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
            }

            // Resolve the target's parent and assert it stays inside the root.
            // This is defense-in-depth on top of the lexical guard above: it
            // catches symlinked parents that resolve outside the root.
            $realTarget = realpath(dirname($target));
            if (
                $realRoot === false
                || $realTarget === false
                || !str_starts_with($realTarget, $realRoot . DIRECTORY_SEPARATOR)
            ) {
                $zip->close();
                return "Refusing to extract entry outside project root: $relative" . PHP_EOL;
            }

            if (str_ends_with($name, '/')) {
                continue;
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