<?php

namespace Unit;

use Lucent\Http\HttpClient;
use PHPUnit\Framework\TestCase;

class HttpClientTest extends TestCase
{

    public function test_http_client_download(): void
    {
        $client = new HttpClient();

        $download_url = "https://file-examples.com/storage/fe21422a6d67aa28993b797/2017/10/file-example_PDF_1MB.pdf";
        $saved_path = 'downloaded.pdf';  // Added filename

        // Ensure directory exists
        $dir = dirname($saved_path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $response = $client->download($download_url, $saved_path);

        $this->assertTrue(file_exists(EXTERNAL_ROOT."storage/downloads/".$saved_path) && $response->successful());
    }

    public function test_http_client_download_404(): void
    {

        $client = new HttpClient();

        $download_url = "https://file-examples.com/storage/fe21422a6d67aa28993b797/2017/10/file-example_PDF_1MB.pdfasdad";
        $saved_path = 'downloaded.pdf';  // Added filename

        // Ensure directory exists
        $dir = dirname($saved_path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $response = $client->download($download_url, $saved_path);

        $this->assertFalse(!file_exists(EXTERNAL_ROOT."storage/downloads/".$saved_path) && !$response->successful());

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