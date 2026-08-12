<?php

namespace Lucent\Facades;

use Lucent\Application;
use Lucent\Http\Client\Client;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Facade for the shared PSR-18 HTTP client.
 *
 * ```php
 * $response = Http::get('https://api.example.com/users');
 * $response = Http::post('https://api.example.com/users', ['name' => 'Jane']);
 * ```
 *
 * The underlying {@see Client} is created lazily and registered on the
 * Application service container, so `Http::client()` always returns the same
 * shared instance.
 */
class Http
{
    /** @var Client|null Cached shared client instance */
    private static ?Client $client = null;

    /**
     * Get the shared Client instance, registering it on the container.
     */
    public static function client(): Client
    {
        if (self::$client === null) {
            self::$client = new Client();
            Application::getInstance()->container()->instance(self::$client);
        }

        return self::$client;
    }

    /**
     * Send a PSR-7 request.
     *
     * @param array<string, mixed> $options Per-request options (sink, timeout, verify_ssl, headers, curl, ...)
     */
    public static function sendRequest(RequestInterface $request, array $options = []): ResponseInterface
    {
        return self::client()->sendRequest($request, $options);
    }

    /**
     * Send a GET request.
     *
     * @param string $uri Request URI (absolute, or relative to `base_uri`)
     * @param array<string, mixed> $params Query parameters to append
     * @param array<string, mixed> $options Per-request options (sink, timeout, verify_ssl, headers, curl, ...)
     */
    public static function get(string $uri, array $params = [], array $options = []): ResponseInterface
    {
        return self::client()->get($uri, $params, $options);
    }

    /**
     * Send a POST request. Arrays are JSON-encoded.
     *
     * @param string $uri Request URI
     * @param array<mixed>|string|StreamInterface $body Request body
     * @param array<string, mixed> $options Per-request options (sink, timeout, verify_ssl, headers, curl, ...)
     */
    public static function post(string $uri, array|string|StreamInterface $body = [], array $options = []): ResponseInterface
    {
        return self::client()->post($uri, $body, $options);
    }

    /**
     * Send a PUT request. Arrays are JSON-encoded.
     *
     * @param string $uri Request URI
     * @param array<mixed>|string|StreamInterface $body Request body
     * @param array<string, mixed> $options Per-request options (sink, timeout, verify_ssl, headers, curl, ...)
     */
    public static function put(string $uri, array|string|StreamInterface $body = [], array $options = []): ResponseInterface
    {
        return self::client()->put($uri, $body, $options);
    }

    /**
     * Send a PATCH request. Arrays are JSON-encoded.
     *
     * @param string $uri Request URI
     * @param array<mixed>|string|StreamInterface $body Request body
     * @param array<string, mixed> $options Per-request options (sink, timeout, verify_ssl, headers, curl, ...)
     */
    public static function patch(string $uri, array|string|StreamInterface $body = [], array $options = []): ResponseInterface
    {
        return self::client()->patch($uri, $body, $options);
    }

    /**
     * Send a DELETE request. Arrays are JSON-encoded.
     *
     * @param string $uri Request URI
     * @param array<mixed>|string|StreamInterface $body Request body
     * @param array<string, mixed> $options Per-request options (sink, timeout, verify_ssl, headers, curl, ...)
     */
    public static function delete(string $uri, array|string|StreamInterface $body = [], array $options = []): ResponseInterface
    {
        return self::client()->delete($uri, $body, $options);
    }

    /**
     * Send a HEAD request.
     *
     * @param string $uri Request URI
     * @param array<string, mixed> $params Query parameters to append
     * @param array<string, mixed> $options Per-request options (sink, timeout, verify_ssl, headers, curl, ...)
     */
    public static function head(string $uri, array $params = [], array $options = []): ResponseInterface
    {
        return self::client()->head($uri, $params, $options);
    }

    /**
     * Swap the shared client instance (primarily for tests).
     */
    public static function swap(?Client $client): void
    {
        self::$client = $client;
    }
}
