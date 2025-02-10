<?php

namespace Lucent\Http;

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

        Log::channel("db")->info("Starting download from {$fullUrl}");

        $ch = curl_init();
        $options = [
            CURLOPT_URL => $fullUrl,
            CURLOPT_RETURNTRANSFER => false, // Don't return the body
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_SSL_VERIFYPEER => $this->verifySSL,
            CURLOPT_SSL_VERIFYHOST => $this->verifySSL ? 2 : 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5
        ];

        // Open file for writing
        $fileHandle = fopen($destinationPath, 'wb');
        $options[CURLOPT_FILE] = $fileHandle;

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

        // Close file handle
        fclose($fileHandle);

        curl_close($ch);

        // Handle download errors
        if ($errno) {
            // Remove the file if download failed
            if (file_exists($destinationPath)) {
                unlink($destinationPath);
            }
            Log::channel("db")->error("Download Error ({$errno}): {$error}");
        }

        return new HttpResponse(
            body: $destinationPath, // Return the path of the downloaded file
            statusCode: $info['http_code'],
            headers: $info,
            error: $error,
            errorCode: $errno
        );
    }

    private function request(string $method, string $url, array|string|null $data = null): HttpResponse
    {
        $fullUrl = $this->baseUrl ? rtrim($this->baseUrl, '/') . '/' . ltrim($url, '/') : $url;

        Log::channel("db")->info("Starting {$method} request to {$fullUrl}");

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
            Log::channel("db")->error("cURL Error ({$errno}): {$error}");
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