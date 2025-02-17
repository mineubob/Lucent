<?php

namespace Unit;

use Lucent\Facades\File;
use Lucent\Http\HttpClient;
use PHPUnit\Framework\TestCase;

class HttpClientTest extends TestCase
{

    public function test_http_client_download(): void
    {
        $client = new HttpClient();

        $download_url = "https://jackgharris.com/test.csv";
        $saved_path = 'downloaded.pdf';  // Added filename

        // Ensure directory exists
        $dir = dirname($saved_path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $response = $client->download($download_url, $saved_path);

        $this->assertTrue(file_exists(File::rootPath()."storage/downloads/".$saved_path) && $response->successful());
    }

    public function test_http_client_download_404(): void
    {

        $client = new HttpClient();

        $download_url = "https://jackgharris.com/testasdasdas.csv";
        $saved_path = 'downloaded2.pdf';  // Added filename

        // Ensure directory exists
        $dir = dirname($saved_path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $response = $client->download($download_url, $saved_path);

        $this->assertFalse(file_exists(File::rootPath()."storage/downloads/".$saved_path) && !$response->successful());

    }

    public function test_http_client_download_invalid_hostname(): void
    {
        $client = new HttpClient();

        $download_url = "https://not-a-file-abasd-2313.com.au";
        $saved_path = __DIR__ . 'downloaded.pdf';  // Added filename

        // Ensure directory exists
        $dir = dirname($saved_path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $response = $client->download($download_url, $saved_path);

        $this->assertFalse(file_exists($saved_path) && $response->successful());
    }


}