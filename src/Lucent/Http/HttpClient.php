<?php

namespace Lucent\Http;

use Lucent\Facades\File;
use Lucent\Facades\Log;

class HttpClient
{
    private array $headers = [];
    private array $options = [];
    private ?string $baseUrl = null;
    private int $timeout = 30;
    private bool $verifySSL = true;
    private ?string $userAgent = null;
    private array $auth = [];

    public function __construct(?string $baseUrl = null)
    {
        $this->baseUrl = $baseUrl;
        $this->userAgent = 'Lucent-HttpClient/1.0';
    }

    public function withHeaders(array $headers): self
    {
        $this->headers = array_merge($this->headers, $headers);
        return $this;
    }

    public function withTimeout(int $seconds): self
    {
        $this->timeout = $seconds;
        return $this;
    }

    public function withoutSSLVerification(): self
    {
        $this->verifySSL = false;
        return $this;
    }

    public function withBasicAuth(string $username, string $password): self
    {
        $this->auth = ['username' => $username, 'password' => $password];
        return $this;
    }

    public function withUserAgent(string $userAgent): self
    {
        $this->userAgent = $userAgent;
        return $this;
    }

    public function get(string $url, array $params = []): HttpResponse
    {
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }
        return $this->request('GET', $url);
    }

    public function post(string $url, array|string $data = []): HttpResponse
    {
        return $this->request('POST', $url, $data);
    }

    public function put(string $url, array|string $data = []): HttpResponse
    {
        return $this->request('PUT', $url, $data);
    }

    public function patch(string $url, array|string $data = []): HttpResponse
    {
        return $this->request('PATCH', $url, $data);
    }

    public function delete(string $url, array $data = []): HttpResponse
    {
        return $this->request('DELETE', $url, $data);
    }

    /**
     * Send a HEAD request to retrieve headers without downloading the body
     *
     * @param string $url URL to request
     * @param array $params Optional query parameters
     * @return HttpResponse
     */
    public function head(string $url, array $params = []): HttpResponse
    {
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }
        return $this->request('HEAD', $url);
    }

    /**
     * Download a file from the specified URL to the given destination path
     *
     * @param string $url URL to download from
     * @param string $destinationPath Path to save the file in storage/downloads directory
     * @param callable|null $progressCallback Optional callback function that receives ($downloadedBytes, $totalBytes)
     * @return HttpResponse
     */
    public function download(string $url, string $destinationPath, ?callable $progressCallback = null): HttpResponse
    {
        $fullUrl = $this->baseUrl ? rtrim($this->baseUrl, '/') . '/' . ltrim($url, '/') : $url;
        $downloadPath = File::rootPath()."storage".DIRECTORY_SEPARATOR."downloads".DIRECTORY_SEPARATOR;

        Log::channel("phpunit")->info("Starting download from {$fullUrl}");

        // Create download directory if it doesn't exist
        if (!is_dir($downloadPath)) {
            mkdir($downloadPath, 0755, true);
        }

        // Ensure we have a callback function (use empty function if none provided)
        $progressCallback = $progressCallback ?? function($downloaded, $total) {};

        // First, determine the file size using a HEAD request that follows redirects
        $headResponse = $this->head($fullUrl);
        if(!$headResponse->successful()){
            Log::channel("phpunit")->error("Failed to download {$fullUrl}");
            die;
        }
        $contentLength = $headResponse->headers()["download_content_length"];

        Log::channel("phpunit")->info("File size determined: {$contentLength} bytes");

        // Open file for writing
        $fp = fopen($downloadPath . DIRECTORY_SEPARATOR . $destinationPath, 'w+');
        if (!$fp) {
            $errorMsg = "Failed to open file for writing: {$destinationPath}";
            Log::channel("phpunit")->error($errorMsg);
            return new HttpResponse(
                body: null,
                statusCode: 0,
                headers: [],
                error: $errorMsg,
                errorCode: -1
            );
        }

        // Set up cURL for the actual download
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $fullUrl,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER => false,
            CURLOPT_FILE => $fp,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_SSL_VERIFYPEER => $this->verifySSL,
            CURLOPT_SSL_VERIFYHOST => $this->verifySSL ? 2 : 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => function($resource, $downloadSize, $downloaded, $uploadSize, $uploaded) use ($progressCallback, $contentLength) {
                // Use the best available size information
                $total = ($downloadSize > 0) ? $downloadSize : $contentLength;
                call_user_func($progressCallback, $downloaded, $total);
            }
        ]);

        // Add headers if any
        $headerArray = [];
        foreach ($this->headers as $key => $value) {
            $headerArray[] = "{$key}: {$value}";
        }

        if (!empty($headerArray)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headerArray);
        }

        // Add authentication if needed
        if (!empty($this->auth)) {
            curl_setopt($ch, CURLOPT_USERPWD, $this->auth['username'] . ':' . $this->auth['password']);
        }

        // Start download
        $success = curl_exec($ch);
        $info = curl_getinfo($ch);
        $error = curl_error($ch);
        $errno = curl_errno($ch);

        // Close file handle
        fclose($fp);

        // Check for errors
        if (!$success || $errno) {
            Log::channel("phpunit")->error("Download Error ({$errno}): {$error}");
            curl_close($ch);
            // Try to remove the incomplete file
            @unlink($downloadPath . DIRECTORY_SEPARATOR . $destinationPath);
            return new HttpResponse(
                body: null,
                statusCode: $info['http_code'],
                headers: $info,
                error: $error,
                errorCode: $errno
            );
        }

        curl_close($ch);

        // Ensure we got a successful HTTP status code
        if ($info['http_code'] !== 200) {
            $errorMsg = "HTTP Error: Received status code {$info['http_code']}";
            Log::channel("phpunit")->error($errorMsg);
            // Try to remove the incomplete file
            @unlink($downloadPath . DIRECTORY_SEPARATOR . $destinationPath);
            return new HttpResponse(
                body: null,
                statusCode: $info['http_code'],
                headers: $info,
                error: $errorMsg,
                errorCode: $info['http_code']
            );
        }

        return new HttpResponse(
            body: $destinationPath,
            statusCode: $info['http_code'],
            headers: $info,
            error: null,
            errorCode: 0
        );
    }


    private function request(string $method, string $url, array|string|null $data = null): HttpResponse
    {
        $fullUrl = $this->baseUrl ? rtrim($this->baseUrl, '/') . '/' . ltrim($url, '/') : $url;

        Log::channel("phpunit")->info("Starting {$method} request to {$fullUrl}");

        $ch = curl_init();
        $options = [
            CURLOPT_URL => $fullUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_SSL_VERIFYPEER => $this->verifySSL,
            CURLOPT_SSL_VERIFYHOST => $this->verifySSL ? 2 : 0,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_FOLLOWLOCATION => true

        ];

        // Handle headers
        $headers = [];
        foreach ($this->headers as $key => $value) {
            $headers[] = "{$key}: {$value}";
        }

        if (!empty($headers)) {
            $options[CURLOPT_HTTPHEADER] = $headers;
        }

        // Handle authentication
        if (!empty($this->auth)) {
            $options[CURLOPT_USERPWD] = $this->auth['username'] . ':' . $this->auth['password'];
        }

        // Handle request body
        if ($data !== null) {
            if (is_array($data)) {
                // Check if we're sending files (multipart/form-data)
                $containsFiles = $this->containsFiles($data);
                if ($containsFiles) {
                    $options[CURLOPT_POSTFIELDS] = $data;
                } else {
                    // Default to JSON if no files
                    $options[CURLOPT_POSTFIELDS] = json_encode($data);
                    $headers[] = 'Content-Type: application/json';
                    $options[CURLOPT_HTTPHEADER] = $headers;
                }
            } else {
                $options[CURLOPT_POSTFIELDS] = $data;
            }
        }

        curl_setopt_array($ch, $options);

        // Execute request
        $response = curl_exec($ch);
        $info = curl_getinfo($ch);
        $error = curl_error($ch);
        $errno = curl_errno($ch);

        curl_close($ch);

        // Log any errors
        if ($error) {
            Log::channel("phpunit")->error("cURL Error ({$errno}): {$error}");
        }

        return new HttpResponse(
            body: $response,
            statusCode: $info['http_code'],
            headers: $info,
            error: $error,
            errorCode: $errno
        );
    }

    private function containsFiles(array $data): bool
    {
        foreach ($data as $value) {
            if (is_string($value) && str_starts_with($value, '@') && is_file(substr($value, 1))) {
                return true;
            }
            if ($value instanceof \CURLFile) {
                return true;
            }
        }
        return false;
    }
}