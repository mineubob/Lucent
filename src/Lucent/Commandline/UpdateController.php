<?php

namespace Lucent\Commandline;

use Exception;
use Lucent\Http\HttpClient;
use Phar;

class UpdateController
{

    private Updater $updater;

    public function __construct(){
        $this->updater = new Updater();
    }

    public function checkUpdate(): string
    {
        // Directly get version from PHAR metadata
        $currentVersion = null;
        if (Phar::running()) {
            try {
                $phar = new Phar(Phar::running(false));
                $metadata = $phar->getMetadata();
                $currentVersion = $metadata['version'] ?? null;
            } catch (Exception $e) {
                return "Could not retrieve current version: " . $e->getMessage();
            }
        }

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
                    return "You're running the latest version of Lucent (v{$currentVersion}). 👍";
                }
            }

            return "Unable to check for updates. Please check your internet connection.";
        } catch (Exception $e) {
            return "Update check failed: " . $e->getMessage();
        }
    }

}