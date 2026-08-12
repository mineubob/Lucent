<?php

namespace Lucent\Http\Client;

use Lucent\Http\Client\Exception\RequestException;
use Lucent\Http\Client\Handler\CurlHandler;
use Lucent\Http\Client\Handler\HandlerInterface;
use Lucent\Http\Client\Handler\StreamHandler;
use Lucent\Http\Message\Factory\HttpFactory;
use Lucent\Http\Message\Stream;
use Lucent\Http\Message\Uri;
use Lucent\Http\Message\UriResolver;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

/**
 * PSR-18 HTTP client.
 *
 * Implements {@see ClientInterface} and reuses Lucent's PSR-7/17 message
 * implementations. Configuration is passed as an immutable config array:
 *
 * ```php
 * $client = new Client([
 *     'base_uri'    => 'https://api.example.com/v1',
 *     'timeout'     => 30,
 *     'verify_ssl'  => true,
 *     'basic_auth'  => ['username', 'password'],
 *     'user_agent'  => 'MyApp/1.0',
 *     'headers'     => ['Accept' => 'application/json'],
 *     'curl_options'=> [CURLOPT_FOLLOWLOCATION => true],
 * ]);
 * ```
 *
 * Per-request options (sink, timeout, verify_ssl, headers, curl, user_agent,
 * basic_auth, stream, progress) are passed as an options array — see the verb
 * methods and {@see sendRequest()}.
 *
 * The transport is pluggable: `sendRequest()` resolves the URI, merges config
 * defaults, then dispatches to a {@see HandlerInterface}. By default the
 * cURL-backed {@see CurlHandler} is used; the `stream => true` option routes to
 * the {@see StreamHandler} for true incremental response streaming.
 */
final class Client implements ClientInterface
{
    /** @var string Option key for the sink (file path, resource, or stream) */
    public const OPTION_SINK = 'sink';

    /** @var string Option key for per-request timeout */
    public const OPTION_TIMEOUT = 'timeout';

    /** @var string Option key for per-request SSL verification */
    public const OPTION_VERIFY_SSL = 'verify_ssl';

    /** @var string Option key for per-request headers */
    public const OPTION_HEADERS = 'headers';

    /** @var string Option key for per-request query params */
    public const OPTION_QUERY = 'query';

    /** @var string Option key for per-request curl options */
    public const OPTION_CURL = 'curl';

    /** @var string Option key for per-request user agent */
    public const OPTION_USER_AGENT = 'user_agent';

    /** @var string Option key for per-request basic auth */
    public const OPTION_BASIC_AUTH = 'basic_auth';

    /** @var string Option key for a per-request progress callback */
    public const OPTION_PROGRESS = 'progress';

    /** @var string Option key for streaming the response body */
    public const OPTION_STREAM = 'stream';

    /**
     * Generate the default User-Agent string.
     *
     * Used as the client's config default and by handlers as a fallback when
     * the merged options omit `user_agent` (e.g. when a handler is used
     * directly). Centralized here so every transport sends the same value.
     * 
     * @return string The default User-Agent string of a Client.
     */
    public static function defaultUserAgent(): string
    {
        return 'Lucent-HttpClient/' . (defined('VERSION') ? VERSION : 'unknown');
    }

    /** @var UriInterface|null Normalized base URI */
    private readonly ?UriInterface $baseUri;

    /** @var int Default timeout in seconds */
    private readonly int $timeout;

    /** @var bool Whether to verify SSL certificates */
    private readonly bool $verifySsl;

    /** @var array{0: string, 1: string}|null Basic auth credentials */
    private readonly ?array $basicAuth;

    /** @var string User agent */
    private readonly string $userAgent;

    /** @var array<string, string> Default headers */
    private readonly array $headers;

    /** @var array<int, mixed> Additional cURL options */
    private readonly array $curlOptions;

    /** @var HttpFactory PSR-17 factory used to build requests/streams */
    private readonly HttpFactory $factory;

    /** @var HandlerInterface The default (cURL-backed) transport */
    private readonly HandlerInterface $defaultHandler;

    /** @var HandlerInterface The streaming transport */
    private readonly HandlerInterface $streamHandler;

    /**
     * @param array<string, mixed> $config Client configuration
     * @param HandlerInterface|null $defaultHandler The default (cURL) transport
     * @param HandlerInterface|null $streamHandler The streaming transport
     * @throws \InvalidArgumentException On unknown keys, wrong types, or invalid values
     */
    public function __construct(
        array $config = [],
        ?HandlerInterface $defaultHandler = null,
        ?HandlerInterface $streamHandler = null
    ) {
        $this->validateConfig($config);

        $baseUri = $config['base_uri'] ?? null;
        $this->baseUri = $baseUri instanceof UriInterface
            ? $baseUri
            : ($baseUri !== null ? Uri::fromString($baseUri) : null);

        $this->timeout = $config['timeout'] ?? 30;
        $this->verifySsl = $config['verify_ssl'] ?? true;
        $this->basicAuth = $config['basic_auth'] ?? null;
        $this->userAgent = $config['user_agent'] ?? self::defaultUserAgent();
        $this->headers = $config['headers'] ?? [];
        $this->curlOptions = $config['curl_options'] ?? [];
        $this->factory = new HttpFactory();

        $this->defaultHandler = $defaultHandler ?? new CurlHandler();
        $this->streamHandler = $streamHandler ?? new StreamHandler();
    }

    /**
     * Send a PSR-7 request and return a PSR-7 response.
     *
     * Per-request options may be passed as the second argument:
     *
     * ```php
     * $response = $client->sendRequest($request, [
     *     'sink'      => '/tmp/file.bin',
     *     'timeout'   => 5,
     *     'verify_ssl'=> false,
     *     'stream'    => true,
     * ]);
     * ```
     *
     * @param array<string, mixed> $options Per-request options (sink, timeout, verify_ssl, headers, query, curl, user_agent, basic_auth, stream, progress).
     *     NOTE: this second parameter is a Lucent extension — the PSR-18
     *     interface defines only the $request parameter. Callers relying on
     *     strict PSR-18 interop should not pass $options.
     * @throws \Psr\Http\Client\RequestExceptionInterface On request-level failures (invalid request, JSON-encode failure, cURL init failure)
     * @throws \Psr\Http\Client\NetworkExceptionInterface On transport-level failures (DNS, connection, timeout)
     * @throws \Psr\Http\Client\ClientExceptionInterface Parent interface of both of the above
     */
    public function sendRequest(RequestInterface $request, array $options = []): ResponseInterface
    {
        $this->validateOptions($options);

        // Resolve the full URL against the configured base URI. Only relative
        // URIs (no scheme) are resolved; absolute URIs override the base.
        $uri = $request->getUri();
        if ($this->baseUri !== null && $uri->getScheme() === '' && $uri->getHost() === '') {
            $uri = UriResolver::resolve($this->baseUri, $uri);
            $request = $request->withUri($uri);
        }

        $streaming = ($options['stream'] ?? false) === true;

        // Merge config defaults into the options (per-request wins). The
        // associative `headers` and `curl` options are deep-merged so
        // per-request keys add to (not replace) config defaults. Config curl
        // defaults are skipped on the stream path so a streaming request is
        // not rejected for curl options it never asked for.
        $options = array_merge([
            'timeout' => $this->timeout,
            'verify_ssl' => $this->verifySsl,
            'user_agent' => $this->userAgent,
            'basic_auth' => $this->basicAuth,
            'headers' => [],
            'curl' => [],
        ], $options);

        $options['headers'] = array_merge($this->headers, $options['headers'] ?? []);
        if (!$streaming) {
            // array_replace (not array_merge) — CURLOPT_* keys are integers
            // and array_merge would renumber them, breaking the conflict
            // check and curl_setopt_array.
            $options['curl'] = array_replace($this->curlOptions, $options['curl'] ?? []);
        }

        // Dispatch per-request: stream => true routes to the streaming
        // handler; everything else uses the default (cURL) handler.
        $handler = $streaming ? $this->streamHandler : $this->defaultHandler;

        // Let the handler validate its own options (conflicting curl options,
        // unsupported options) against the merged options.
        $handler->validateOptions($options);

        return $handler->send($request, $options);
    }

    // ─── Verb Convenience Methods ───────────────────────────────────────

    /**
     * Send a GET request.
     *
     * @param string $uri Request URI (absolute, or relative to `base_uri`)
     * @param array<string, mixed> $params Query parameters to append
     * @param array<string, mixed> $options Per-request options (sink, timeout, verify_ssl, headers, curl, ...)
     */
    public function get(string $uri, array $params = [], array $options = []): ResponseInterface
    {
        if (!empty($params)) {
            $separator = str_contains($uri, '?') ? '&' : '?';
            $uri .= $separator . http_build_query($params);
        }

        $request = $this->factory->createRequest('GET', $uri);

        return $this->sendRequest($request, $options);
    }

    /**
     * Send a POST request.
     *
     * Arrays are JSON-encoded and sent with `Content-Type: application/json`.
     *
     * @param string $uri Request URI
     * @param array<mixed>|string|StreamInterface $body Request body
     * @param array<string, mixed> $options Per-request options (sink, timeout, verify_ssl, headers, curl, ...)
     */
    public function post(string $uri, array|string|StreamInterface $body = [], array $options = []): ResponseInterface
    {
        return $this->sendWithBody('POST', $uri, $body, $options);
    }

    /**
     * Send a PUT request.
     *
     * Arrays are JSON-encoded and sent with `Content-Type: application/json`.
     *
     * @param string $uri Request URI
     * @param array<mixed>|string|StreamInterface $body Request body
     * @param array<string, mixed> $options Per-request options (sink, timeout, verify_ssl, headers, curl, ...)
     */
    public function put(string $uri, array|string|StreamInterface $body = [], array $options = []): ResponseInterface
    {
        return $this->sendWithBody('PUT', $uri, $body, $options);
    }

    /**
     * Send a PATCH request.
     *
     * Arrays are JSON-encoded and sent with `Content-Type: application/json`.
     *
     * @param string $uri Request URI
     * @param array<mixed>|string|StreamInterface $body Request body
     * @param array<string, mixed> $options Per-request options (sink, timeout, verify_ssl, headers, curl, ...)
     */
    public function patch(string $uri, array|string|StreamInterface $body = [], array $options = []): ResponseInterface
    {
        return $this->sendWithBody('PATCH', $uri, $body, $options);
    }

    /**
     * Send a DELETE request.
     *
     * Arrays are JSON-encoded and sent with `Content-Type: application/json`.
     *
     * @param string $uri Request URI
     * @param array<mixed>|string|StreamInterface $body Request body
     * @param array<string, mixed> $options Per-request options (sink, timeout, verify_ssl, headers, curl, ...)
     */
    public function delete(string $uri, array|string|StreamInterface $body = [], array $options = []): ResponseInterface
    {
        return $this->sendWithBody('DELETE', $uri, $body, $options);
    }

    /**
     * Send a HEAD request.
     *
     * @param string $uri Request URI
     * @param array<string, mixed> $params Query parameters to append
     * @param array<string, mixed> $options Per-request options (sink, timeout, verify_ssl, headers, curl, ...)
     */
    public function head(string $uri, array $params = [], array $options = []): ResponseInterface
    {
        if (!empty($params)) {
            $separator = str_contains($uri, '?') ? '&' : '?';
            $uri .= $separator . http_build_query($params);
        }

        $request = $this->factory->createRequest('HEAD', $uri);

        return $this->sendRequest($request, $options);
    }

    // ─── Internals ──────────────────────────────────────────────────────

    /**
     * @param array<mixed>|string|StreamInterface $body
     * @param array<string, mixed> $options
     */
    private function sendWithBody(string $method, string $uri, array|string|StreamInterface $body, array $options): ResponseInterface
    {
        $request = $this->factory->createRequest($method, $uri);

        if (is_array($body)) {
            $encoded = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                throw new RequestException(
                    'Unable to JSON-encode request body: ' . json_last_error_msg(),
                    $request
                );
            }

            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody(Stream::fromString($encoded));
        } elseif ($body instanceof StreamInterface) {
            $request = $request->withBody($body);
        } else {
            $request = $request->withBody(Stream::fromString($body));
        }

        return $this->sendRequest($request, $options);
    }

    /**
     * Validate the config array, throwing on unknown keys or invalid values.
     *
     * @param array<string, mixed> $config
     * @throws \InvalidArgumentException
     */
    private function validateConfig(array $config): void
    {
        $allowed = ['base_uri', 'timeout', 'verify_ssl', 'basic_auth', 'user_agent', 'headers', 'curl_options'];

        foreach ($config as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                throw new \InvalidArgumentException("Unknown Client config key: {$key}");
            }
        }

        if (array_key_exists('base_uri', $config) && !is_string($config['base_uri']) && !$config['base_uri'] instanceof UriInterface) {
            throw new \InvalidArgumentException('base_uri must be a string or UriInterface');
        }

        if (array_key_exists('timeout', $config) && (!is_int($config['timeout']) || $config['timeout'] <= 0)) {
            throw new \InvalidArgumentException('timeout must be a positive integer');
        }

        if (array_key_exists('verify_ssl', $config) && !is_bool($config['verify_ssl'])) {
            throw new \InvalidArgumentException('verify_ssl must be a boolean');
        }

        if (array_key_exists('basic_auth', $config)) {
            $basicAuth = $config['basic_auth'];
            if ($basicAuth !== null
                && (!is_array($basicAuth)
                    || count($basicAuth) !== 2
                    || !is_string($basicAuth[0] ?? null)
                    || !is_string($basicAuth[1] ?? null))) {
                throw new \InvalidArgumentException('basic_auth must be an array of [username, password] strings');
            }
        }

        if (array_key_exists('user_agent', $config) && !is_string($config['user_agent'])) {
            throw new \InvalidArgumentException('user_agent must be a string');
        }

        if (array_key_exists('headers', $config) && !is_array($config['headers'])) {
            throw new \InvalidArgumentException('headers must be an array');
        }

        if (array_key_exists('curl_options', $config) && !is_array($config['curl_options'])) {
            throw new \InvalidArgumentException('curl_options must be an array');
        }

        // Fail fast on config-level curl options that conflict with the
        // handler's own transport handling (single source of truth lives in
        // CurlHandler so per-request `curl` options are checked identically).
        if (array_key_exists('curl_options', $config)) {
            foreach ($config['curl_options'] as $option => $_) {
                if (isset(CurlHandler::CONFLICTING_CURL_OPTIONS[$option])) {
                    throw new \InvalidArgumentException(
                        'curl_options must not override ' . CurlHandler::CONFLICTING_CURL_OPTIONS[$option]
                    );
                }
            }
        }
    }

    /**
     * Validate per-request options, throwing on unknown keys or invalid values.
     *
     * @param array<string, mixed> $options
     * @throws \InvalidArgumentException
     */
    private function validateOptions(array $options): void
    {
        $allowed = ['sink', 'timeout', 'verify_ssl', 'headers', 'curl', 'user_agent', 'basic_auth', 'query', 'progress', 'stream'];

        foreach ($options as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                throw new \InvalidArgumentException("Unknown Client request option: {$key}");
            }
        }

        if (array_key_exists('stream', $options) && !is_bool($options['stream'])) {
            throw new \InvalidArgumentException('stream must be a boolean');
        }

        if (array_key_exists('timeout', $options) && (!is_int($options['timeout']) || $options['timeout'] <= 0)) {
            throw new \InvalidArgumentException('timeout must be a positive integer');
        }

        if (array_key_exists('verify_ssl', $options) && !is_bool($options['verify_ssl'])) {
            throw new \InvalidArgumentException('verify_ssl must be a boolean');
        }

        if (array_key_exists('headers', $options) && !is_array($options['headers'])) {
            throw new \InvalidArgumentException('headers must be an array');
        }

        if (array_key_exists('curl', $options) && !is_array($options['curl'])) {
            throw new \InvalidArgumentException('curl must be an array');
        }

        if (array_key_exists('basic_auth', $options) && $options['basic_auth'] !== null) {
            $basicAuth = $options['basic_auth'];
            if (!is_array($basicAuth)
                || count($basicAuth) !== 2
                || !is_string($basicAuth[0] ?? null)
                || !is_string($basicAuth[1] ?? null)) {
                throw new \InvalidArgumentException('basic_auth must be an array of [username, password] strings');
            }
        }
    }
}