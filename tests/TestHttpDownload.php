<?php

use Lucent\Http\HttpClient;
use Lucent\Commandline\Components\ProgressBar;

require_once '/Users/jackharris/PhpstormProjects/Lucent/temp_install/packages/lucent.phar';

/**
 * Standalone script to test HTTP download with progress bar
 */
class TestHttpDownload
{
    /**
     * Run the download test
     */
    public function run(): bool
    {
        echo "Starting download test with progress bar\n";
        echo "---------------------------------------\n";

        $client = new HttpClient();
        $client->withTimeout(30);
        $outcome = false;

        // First get the release info
        $response = $client->get('https://api.github.com/repos/jackharrispeninsulainteractive/Lucent/releases/latest');

        if ($response->successful()) {
            $latestRelease = $response->json();
            $downloadUrl = $latestRelease['assets'][0]['browser_download_url'];

            // Use a simple filename instead of a full path
            $tempFileName = "tempLucentDownload.phar";

            $headResponse = $client->head($downloadUrl);

            // Get file size using a HEAD request with redirect following
            $fileSize = $headResponse->headers()["download_content_length"];

            if ($fileSize <= 0) {
                echo "Unable to extract file size from head request.\n";
                die;
            } else {
                echo "File size from HEAD request: " . $this->formatSize($fileSize) . "\n";
            }

            $startTime = microtime(true);

            // Initialize progress bar with the known file size
            $progressBar = new ProgressBar($fileSize);
            $progressBar->setFormat('[{bar}] {percent}% of ' . $this->formatSize($fileSize) . ' - {elapsed} elapsed - {eta} remaining');

            // Download with progress callback
            $downloadResponse = $client->download($downloadUrl, $tempFileName, function ($downloaded, $total) use ($progressBar, $fileSize) {
                $progressBar->update((int)$downloaded);
            });

            // Calculate download time
            $downloadTime = microtime(true) - $startTime;

            // Finish progress bar
            $progressBar->finish();

            // Get the expected download path based on your HttpClient implementation
            $rootPath = '/Users/jackharris/PhpstormProjects/Lucent/temp_install/storage/downloads/';
            $actualFilePath = $rootPath . $tempFileName;

            if ($downloadResponse->status() == 200 && file_exists($actualFilePath)) {
                $actualSize = filesize($actualFilePath);

                echo "\n";
                echo "Download SUCCESSFUL!\n";
                echo "File: $actualFilePath\n";
                echo "Size: " . $this->formatSize($actualSize) . "\n";
                echo "Time: " . round($downloadTime, 2) . " seconds\n";
                echo "Speed: " . $this->formatSize((int)($actualSize / max(0.1, $downloadTime))) . "/s\n";

                // Clean up the test file
                echo "Cleaning up test file...\n";
                unlink($actualFilePath);

                $outcome = true;
            } else {
                echo "\nError: File not found after download or download failed\n";
                echo "Expected file path: $actualFilePath\n";
                echo "HTTP Status: " . $downloadResponse->status() . "\n";
                if ($downloadResponse->error()) {
                    echo "Error: " . $downloadResponse->error() . "\n";
                }
                $outcome = false;
            }
        } else {
            echo "Error: Failed to get latest release information\n";
            echo "HTTP Status: " . $response->status() . "\n";
            if ($response->error()) {
                echo "Error: " . $response->error() . "\n";
            }
        }

        return $outcome;
    }

    /**
     * Format file size in human readable format
     *
     * @param int $bytes Size in bytes
     * @return string Formatted size
     */
    private function formatSize(int $bytes): string
    {
        if ($bytes <= 0) {
            return "0 B";
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }
}

// Run the test if executed directly
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['PHP_SELF'])) {
    $test = new TestHttpDownload();
    $result = $test->run();

    exit($result ? 0 : 1);
}