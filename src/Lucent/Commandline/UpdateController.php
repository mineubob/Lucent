<?php

namespace Lucent\Commandline;

use Exception;
use Lucent\Facades\App;
use Lucent\Http\HttpClient;
use Phar;

class UpdateController
{


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
                        "Update available! 🚀\n" .
                        "Current version: %s\n" .
                        "Latest version:  %s\n\n" .
                        "Download: %s\n\n" .
                        "Release Notes:\n%s",
                        $currentVersion,
                        $latestVersion,
                        $latestRelease['assets'][0]['browser_download_url'],
                        $latestRelease['body'] ?? 'No release notes available.'
                    );
                } else {
                    return "You're running the latest version of Lucent ({$currentVersion}). 👍\n";
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

                if (version_compare($currentVersion, $latestVersion, '<') || str_contains($currentVersion,"local")) {
                    // Prepare paths
                    $downloadUrl = $latestRelease['assets'][0]['browser_download_url'];

                    $newPharPath = dirname($currentPharPath) . "/lucent-{$latestVersion}.phar";
                    $backupPharPath = dirname($currentPharPath) . "/lucent-{$currentVersion}.phar.old";

                    // Download new PHAR using the new download method
                    $downloadResponse = $client->download($downloadUrl, $newPharPath);

                    if (!$downloadResponse->successful()) {
                        return "Failed to download update: " . $downloadResponse->error();
                    }

                    // Make executable
                    chmod($newPharPath, 0755);

                    // Backup current PHAR
                    copy($currentPharPath, $backupPharPath);

                    // Replace current PHAR
                    rename($newPharPath, $currentPharPath);

                    return sprintf(
                        "Successfully updated Lucent! 🎉\n" .
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
            if (isset($newPharPath) && file_exists($newPharPath)) {
                unlink($newPharPath);
            }
            return "Update check failed: " . $e->getMessage();
        }
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
}