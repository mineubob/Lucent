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

    public function download(string $url, string $destinationPath): HttpResponse
    {
        $fullUrl = $this->baseUrl ? rtrim($this->baseUrl, '/') . '/' . ltrim($url, '/') : $url;
        $downloadPath = File::rootPath()."storage".DIRECTORY_SEPARATOR."downloads".DIRECTORY_SEPARATOR;

        Log::channel("phpunit")->info("Starting download from {$fullUrl}");

        $ch = curl_init();
        $options = [
            CURLOPT_URL => $fullUrl,
            CURLOPT_RETURNTRANSFER => true, // Changed to true to check response
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_SSL_VERIFYPEER => $this->verifySSL,
            CURLOPT_SSL_VERIFYHOST => $this->verifySSL ? 2 : 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_HEADER => true // Get headers in response
        ];

        // Handle headers
        $headers = [];
        foreach ($this->headers as $key => $value) {
            $headers[] = "{$key}: {$value}";
        }

        if (!empty($headers)) {
            $options[CURLOPT_HTTPHEADER] = $headers;
        }

        curl_setopt_array($ch, $options);

        // Execute request
        $response = curl_exec($ch);
        $info = curl_getinfo($ch);
        $error = curl_error($ch);
        $errno = curl_errno($ch);

        // Check for curl errors first
        if ($errno) {
            Log::channel("phpunit")->error("Download Error ({$errno}): {$error}");
            return new HttpResponse(
                body: null,
                statusCode: 0,
                headers: $info,
                error: $error,
                errorCode: $errno
            );
        }

        // Check HTTP status code
        if ($info['http_code'] !== 200) {
            $errorMsg = "HTTP Error: Received status code {$info['http_code']}";
            Log::channel("phpunit")->error($errorMsg);

            // Try to parse response body for error details
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $body = substr($response, $headerSize);

            if ($json = json_decode($body, true)) {
                $errorMsg .= " - " . ($json['message'] ?? 'Unknown error');
            }

            curl_close($ch);

            return new HttpResponse(
                body: null,
                statusCode: $info['http_code'],
                headers: $info,
                error: $errorMsg,
                errorCode: $info['http_code']
            );
        }

        // Extract body without headers
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $body = substr($response, $headerSize);

        // Validate response body
        if (empty($body)) {
            $errorMsg = "Empty response received";
            Log::channel("phpunit")->error($errorMsg);
            curl_close($ch);
            return new HttpResponse(
                body: null,
                statusCode: $info['http_code'],
                headers: $info,
                error: $errorMsg,
                errorCode: -1
            );
        }

        if (!is_dir($downloadPath)) {
            mkdir($downloadPath, 0755, true);
        }

        // Write to file
        if (file_put_contents($downloadPath.DIRECTORY_SEPARATOR.$destinationPath, $body) === false) {
            $errorMsg = "Failed to write to storage/downloads/{$destinationPath}";
            Log::channel("phpunit")->error($errorMsg);
            curl_close($ch);
            return new HttpResponse(
                body: null,
                statusCode: $info['http_code'],
                headers: $info,
                error: $errorMsg,
                errorCode: -1
            );
        }

        curl_close($ch);

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
            CURLOPT_CUSTOMREQUEST => $method
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