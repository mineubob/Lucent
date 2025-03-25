<?php

namespace Lucent\Commandline;

use Exception;
use Lucent\Commandline\Components\ProgressBar;
use Lucent\Facades\App;
use Lucent\Facades\File;
use Lucent\Http\HttpClient;
use Lucent\StaticAnalysis\DependencyAnalyser;
use Phar;

class UpdateController
{
    private string $downloadPath;

    public function __construct(){
        $this->downloadPath = File::rootPath(). "storage" . DIRECTORY_SEPARATOR . "downloads" . DIRECTORY_SEPARATOR;
    }

    public function check(): string
    {
        // Directly get version from PHAR metadata
        $currentVersion = App::getLucentVersion();

        if (!$currentVersion) {
            return "Unable to determine current version.";
        }
        try {
            $client = new HttpClient('https://api.github.com');
            $response = $client->get('/repos/jackharrispeninsulainteractive/Lucent/releases/latest');

            if ($response->successful()) {
                $latestRelease = $response->json();
                $latestVersion = $latestRelease['tag_name'];

                if (version_compare($currentVersion, $latestVersion, '<')) {
                    return sprintf(
                        "Update available! 🚀".PHP_EOL .
                        "Current version: %s".PHP_EOL .
                        "Latest version:  %s".PHP_EOL .
                        "Download: %s".PHP_EOL .
                        "Release Notes:".PHP_EOL."%s",
                        $currentVersion,
                        $latestVersion,
                        $latestRelease['assets'][0]['browser_download_url'],
                        $latestRelease['body'] ?? 'No release notes available.'
                    );
                } else {
                    return "You're running the latest version of Lucent ({$currentVersion}). 👍".PHP_EOL;
                }
            }

            return "Unable to check for updates. Please check your internet connection.";
        } catch (Exception $e) {
            return "Update check failed: " . $e->getMessage();
        }
    }

    public function install(): string
    {
        $currentVersion = App::getLucentVersion();
        $currentPharPath = Phar::running(false);

        if (!$currentVersion) {
            return "Unable to determine current version.";
        }

        try {
            $client = new HttpClient();
            $response = $client->get('https://api.github.com/repos/jackharrispeninsulainteractive/Lucent/releases/latest');

            if ($response->successful()) {
                $latestRelease = $response->json();
                $latestVersion = $latestRelease['tag_name'];

                if (version_compare($currentVersion, $latestVersion, '<') || str_contains($currentVersion, "local")) {
                    // Prepare paths
                    $downloadUrl = $latestRelease['assets'][0]['browser_download_url'];
                    $tempFileName = "lucent-{$latestVersion}.phar";
                    $targetPharPath = dirname($currentPharPath) . DIRECTORY_SEPARATOR . "lucent-{$latestVersion}.phar";
                    $backupPharPath = dirname($currentPharPath) . DIRECTORY_SEPARATOR . "lucent-{$currentVersion}.phar.old";

                    // First, use a HEAD request via your client to get the file size
                    $headClient = new HttpClient();
                    $headResponse = $headClient->head($downloadUrl);

                    // Extract file size from the response
                    $fileSize = isset($headResponse->headers()["download_content_length"]) ?
                        (int)$headResponse->headers()["download_content_length"] :
                        1024 * 1024 * 5; // Default 5MB if size can't be determined

                    echo "Downloading Lucent {$latestVersion}..." . PHP_EOL;

                    // Initialize progress bar with file size
                    $progress = new ProgressBar($fileSize);
                    $progress->setFormat('[{bar}] {percent}% of ' . $this->formatFileSize($fileSize) . ' - {eta} remaining');
                    $progress->setBarCharacters(['█', '░']);
                    $progress->setUpdateInterval(0.1); // Update every 100ms for smoother display

                    // Create progress callback
                    $progressCallback = function($downloadedBytes, $totalBytes) use ($progress) {
                        $progress->update($downloadedBytes);
                    };

                    // Download new PHAR using progress callback
                    $downloadResponse = $client->download(
                        $downloadUrl,
                        $tempFileName,
                        $progressCallback
                    );

                    // Complete the progress bar
                    $progress->finish();

                    echo PHP_EOL . "Download complete! Installing..." . PHP_EOL;

                    if (!$downloadResponse->successful()) {
                        return "Failed to download update: " . $downloadResponse->error();
                    }

                    $downloadedFilePath = $this->downloadPath . $tempFileName;

                    // Verify the downloaded file exists
                    if (!file_exists($downloadedFilePath)) {
                        return "Download failed: File not found in downloads directory";
                    }

                    // Copy from downloads to target location
                    if (!copy($downloadedFilePath, $targetPharPath)) {
                        unlink($downloadedFilePath);
                        return "Failed to copy downloaded file to target location";
                    }

                    echo PHP_EOL . "Installation complete! Checking codebase..." . PHP_EOL;

                    $analyser = new DependencyAnalyser();

                    $analyser->parseFiles(File::getFiles(extensions: "php"));

                    $dependencies = $analyser->run();

                    $this->printCompatibilityCheck($dependencies);

                    // Clean up downloaded file
                    unlink($downloadedFilePath);

                    // Make executable
                    chmod($targetPharPath, 0755);

                    // Backup current PHAR
                    copy($currentPharPath, $backupPharPath);

                    // Replace current PHAR
                    rename($targetPharPath, $currentPharPath);

                    return sprintf(
                        "Successfully updated Lucent! 🎉".PHP_EOL .
                        "Old version: %s\n" .
                        "New version: %s\n\n" .
                        "Backup of old version saved at: %s\n\n" .
                        "Release Notes:\n%s",
                        $currentVersion,
                        $latestVersion,
                        $backupPharPath,
                        $latestRelease['body'] ?? 'No release notes available.'
                    );
                } else {
                    return "You're running the latest version of Lucent ({$currentVersion}). 👍\n";
                }
            }

            return "Unable to check for updates. Please check your internet connection.";
        } catch (Exception $e) {
            // Clean up temporary files if they exist
            if (isset($downloadedFilePath) && file_exists($downloadedFilePath)) {
                unlink($downloadedFilePath);
            }
            if (isset($targetPharPath) && file_exists($targetPharPath)) {
                unlink($targetPharPath);
            }
            return "Update check failed: " . $e->getMessage();
        }
    }

    /**
     * Format file size in a human-readable format
     *
     * @param int $bytes File size in bytes
     * @return string Formatted file size
     */
    private function formatFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);

        return round($bytes, 2) . ' ' . $units[$pow];
    }

    public function rollback(): string
    {
        // Get the current running PHAR path
        $currentPharPath = Phar::running(false);
        $pharDirectory = dirname($currentPharPath);

        // Find backup PHARs
        $backupFiles = glob($pharDirectory . '/lucent-*.phar.old');

        if (empty($backupFiles)) {
            return "No backup versions found to roll back to.";
        }

        // Sort backups to get the most recent
        usort($backupFiles, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });

        $backupPharPath = $backupFiles[0];

        // Extract version from backup filename
        preg_match('/lucent-(.+)\.phar\.old/', $backupPharPath, $matches);
        $backupVersion = $matches[1] ?? 'unknown';

        try {
            // Create a backup of the current version (just in case)
            try {
                $phar = new Phar($currentPharPath);
                $metadata = $phar->getMetadata();
                $currentVersion = $metadata['version'];
            } catch (Exception $e) {
                $currentVersion = 'unknown';
            }
            $safetyBackupPath = $pharDirectory . "/lucent-{$currentVersion}.phar.rollback.old";
            copy($currentPharPath, $safetyBackupPath);

            // Replace current PHAR with backup
            copy($backupPharPath, $currentPharPath);

            // Remove the used backup file
            unlink($backupPharPath);

            return sprintf(
                "Rolled back successfully! 🔙\n" .
                "Reverted to version: %s\n\n" .
                "Current version backed up at: %s\n" .
                "Used backup: %s",
                $backupVersion,
                $safetyBackupPath,
                $backupPharPath
            );
        } catch (Exception $e) {
            return "Rollback failed: " . $e->getMessage();
        }
    }

    function printCompatibilityCheck(array $dependencies): void
    {
        // ANSI color codes as variables, not constants
        $COLOR_RED = "\033[31m";
        $COLOR_YELLOW = "\033[33m";
        $COLOR_BLUE = "\033[36m";
        $COLOR_BOLD = "\033[1m";
        $COLOR_RESET = "\033[0m";

        // Count the issues by file
        $fileIssues = [];
        $totalDeprecated = 0;
        $totalRemoved = 0;

        // Show header
        echo $COLOR_BOLD . "UPDATE COMPATIBILITY" . $COLOR_RESET . PHP_EOL;
        echo "============================" . PHP_EOL;

        foreach ($dependencies as $fileName => $file) {
            $fileHasIssues = false;
            $fileDeprecations = 0;
            $fileRemovals = 0;

            foreach ($file as $dependencyName => $dependency) {
                foreach ($dependency as $use) {
                    if (!empty($use["issues"])) {
                        // Count issues by type
                        foreach ($use["issues"] as $issue) {
                            if (isset($issue["status"])) {
                                if ($issue["status"] === "error") {
                                    $fileRemovals++;
                                    $totalRemoved++;
                                } elseif ($issue["status"] === "warning") {
                                    $fileDeprecations++;
                                    $totalDeprecated++;
                                }
                            }
                        }

                        $fileHasIssues = true;

                        // Show file name if this is the first issue in the file
                        if (!isset($fileIssues[$fileName])) {
                            echo $COLOR_BOLD . $fileName . $COLOR_RESET . PHP_EOL;
                            $fileIssues[$fileName] = true;
                        }

                        // Show the dependency usage
                        $lineInfo = "  Line " . str_pad($use["line"], 4, ' ', STR_PAD_LEFT) . ": ";
                        echo $lineInfo . $COLOR_BLUE . $dependencyName . $COLOR_RESET;

                        // Show method if applicable
                        if (isset($use["method"]) && isset($use["method"]["name"])) {
                            echo "->" . $use["method"]["name"] . "()";
                        }

                        echo PHP_EOL;

                        // Show each issue with appropriate color and clear labeling
                        foreach ($use["issues"] as $issue) {
                            $color = $COLOR_YELLOW; // Default for warnings
                            $issueType = "DEPRECATED";

                            if (isset($issue["status"]) && $issue["status"] === "error") {
                                $color = $COLOR_RED;
                                $issueType = "REMOVED";
                            }

                            // Format message
                            $message = $issue["message"] ?? "Unknown issue";
                            $since = "";

                            // Extract version info if available in the message
                            if (preg_match('/since\s+version\s+([0-9.]+)/i', $message, $matches)) {
                                $since = " (since v" . $matches[1] . ")";
                            } else if (preg_match('/since\s+v([0-9.]+)/i', $message, $matches)) {
                                $since = " (since v" . $matches[1] . ")";
                            }

                            // Show scope if provided
                            $scopeText = "";
                            if (isset($issue["scope"])) {
                                $scopeText = " " . $issue["scope"];
                            }

                            echo "    " . $color . "⚠ " . $issueType . $scopeText . $since . ": " . $COLOR_RESET . $message . PHP_EOL;
                        }
                    }
                }
            }

            // Show file summary if issues were found
            if ($fileHasIssues) {
                echo PHP_EOL;
            }
        }

        // Show grand total
        if ($totalDeprecated > 0 || $totalRemoved > 0) {
            echo "============================" . PHP_EOL;
            echo $COLOR_BOLD . "SUMMARY: " . $COLOR_RESET;

            if ($totalRemoved > 0) {
                echo $COLOR_RED . $totalRemoved . " removed" . $COLOR_RESET;
                if ($totalDeprecated > 0) {
                    echo ", ";
                }
            }

            if ($totalDeprecated > 0) {
                echo $COLOR_YELLOW . $totalDeprecated . " deprecated" . $COLOR_RESET;
            }

            echo " components found in " . count($fileIssues) . " files" . PHP_EOL;
            echo "Update your code to ensure compatibility with the latest Lucent version" . PHP_EOL;
        } else {
            echo $COLOR_BOLD . "No compatibility issues found! Your code is up to date." . $COLOR_RESET . PHP_EOL;
        }
    }


}