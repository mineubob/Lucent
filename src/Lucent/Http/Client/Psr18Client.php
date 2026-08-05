<?php

namespace Lucent\Http\Client;

use Lucent\Facades\Log;
use Lucent\Http\Client\Exception\NetworkException;
use Lucent\Http\Client\Exception\RequestException;
use Lucent\Http\Message\Factory\HttpFactory;
use Lucent\Http\Message\Response;
use Lucent\Http\Message\Stream;
use Lucent\Http\Message\Uri;
use Lucent\Http\Message\UriResolver;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

/**
 * PSR-18 HTTP client backed by cURL.
 *
 * Implements {@see ClientInterface} and reuses Lucent's PSR-7/17 message
 * implementations. Configuration is passed as an immutable config array:
 *
 * ```php
 * $client = new Psr18Client([
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
 * basic_auth) are passed as an options array — see the verb methods and
 * {@see sendRequest()}.
 */
final class Psr18Client implements ClientInterface
{
    /** @var string Log channel used by the client */
    private const LOG_CHANNEL = 'lucent.http';

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

    /**
     * cURL options that conflict with the client's own handling and must not
     * be set via `curl_options`.
     *
     * @var array<int, string>
     */
    private const CONFLICTING_CURL_OPTIONS = [
        CURLOPT_URL => 'CURLOPT_URL',
        CURLOPT_CUSTOMREQUEST => 'CURLOPT_CUSTOMREQUEST',
        CURLOPT_RETURNTRANSFER => 'CURLOPT_RETURNTRANSFER',
        CURLOPT_FILE => 'CURLOPT_FILE',
        CURLOPT_WRITEFUNCTION => 'CURLOPT_WRITEFUNCTION',
        CURLOPT_HEADERFUNCTION => 'CURLOPT_HEADERFUNCTION',
        CURLOPT_POSTFIELDS => 'CURLOPT_POSTFIELDS',
        CURLOPT_READFUNCTION => 'CURLOPT_READFUNCTION',
        CURLOPT_INFILESIZE => 'CURLOPT_INFILESIZE',
        CURLOPT_HTTPHEADER => 'CURLOPT_HTTPHEADER',
        CURLOPT_USERPWD => 'CURLOPT_USERPWD',
        CURLOPT_USERAGENT => 'CURLOPT_USERAGENT',
        CURLOPT_TIMEOUT => 'CURLOPT_TIMEOUT',
        CURLOPT_SSL_VERIFYPEER => 'CURLOPT_SSL_VERIFYPEER',
        CURLOPT_SSL_VERIFYHOST => 'CURLOPT_SSL_VERIFYHOST',
        CURLOPT_NOPROGRESS => 'CURLOPT_NOPROGRESS',
        CURLOPT_XFERINFOFUNCTION => 'CURLOPT_XFERINFOFUNCTION',
        CURLOPT_PROGRESSFUNCTION => 'CURLOPT_PROGRESSFUNCTION',
    ];

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

    /**
     * @param array<string, mixed> $config Client configuration
     * @throws \InvalidArgumentException On unknown keys, wrong types, or invalid values
     */
    public function __construct(array $config = [])
    {
        $this->validateConfig($config);

        $baseUri = $config['base_uri'] ?? null;
        $this->baseUri = $baseUri instanceof UriInterface
            ? $baseUri
            : ($baseUri !== null ? Uri::fromString($baseUri) : null);

        $this->timeout = $config['timeout'] ?? 30;
        $this->verifySsl = $config['verify_ssl'] ?? true;
        $this->basicAuth = $config['basic_auth'] ?? null;
        $this->userAgent = $config['user_agent'] ?? 'Lucent-Psr18Client/1.0';
        $this->headers = $config['headers'] ?? [];
        $this->curlOptions = $config['curl_options'] ?? [];
        $this->factory = new HttpFactory();
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
     * ]);
     * ```
     *
     * @param array<string, mixed> $options Per-request options (sink, timeout, verify_ssl, headers, query, curl, user_agent, basic_auth)
     * @throws NetworkException On transport-level failures (DNS, connection, timeout)
     * @throws RequestException On request-level failures (invalid URL, cURL errors)
     */
    public function sendRequest(RequestInterface $request, array $options = []): ResponseInterface
    {
        $this->validateOptions($options);

        $uri = $request->getUri();

        // Resolve the full URL against the configured base URI. Only relative
        // URIs (no scheme) are resolved; absolute URIs override the base.
        if ($this->baseUri !== null && $uri->getScheme() === '' && $uri->getHost() === '') {
            $uri = UriResolver::resolve($this->baseUri, $uri);
            $request = $request->withUri($uri);
        }

        $url = (string) $uri;
        $method = $request->getMethod();

        Log::channel(self::LOG_CHANNEL)->info("Starting {$method} request to {$url}");

        $ch = curl_init();
        if ($ch === false) {
            throw new RequestException('Unable to initialize cURL', $request);
        }

        $options = array_merge([
            'timeout' => $this->timeout,
            'verify_ssl' => $this->verifySsl,
            'user_agent' => $this->userAgent,
            'basic_auth' => $this->basicAuth,
            'headers' => [],
            'curl' => [],
        ], $options);

        $curl = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => $options['timeout'],
            CURLOPT_USERAGENT => $options['user_agent'],
            CURLOPT_SSL_VERIFYPEER => $options['verify_ssl'],
            CURLOPT_SSL_VERIFYHOST => $options['verify_ssl'] ? 2 : 0,
        ];

        // Headers: defaults merged with per-request headers, then request headers (request wins).
        $headers = array_merge($this->headers, $options['headers']);
        foreach ($request->getHeaders() as $name => $values) {
            $headers[$name] = implode(', ', $values);
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = "{$name}: {$value}";
        }
        if (!empty($headerLines)) {
            $curl[CURLOPT_HTTPHEADER] = $headerLines;
        }

        // Basic auth.
        $basicAuth = $options['basic_auth'];
        if ($basicAuth !== null) {
            $curl[CURLOPT_USERPWD] = $basicAuth[0] . ':' . $basicAuth[1];
        }

        // Request body. All bodies are streamed from the StreamInterface via
        // CURLOPT_READFUNCTION — method-agnostic and never fully buffered.
        // The read function returns '' at EOF (which libcurl treats as end of
        // transfer). CURLOPT_INFILESIZE is set only when the size is known so
        // libcurl sends Content-Length; when the stream size is unknown (e.g.
        // IteratorStream) it falls back to Transfer-Encoding: chunked.
        $body = $request->getBody();
        $bodySize = $body->getSize();

        if ($bodySize === null || $bodySize > 0) {
            $curl[CURLOPT_UPLOAD] = true;
            if ($bodySize !== null) {
                $curl[CURLOPT_INFILESIZE] = $bodySize;
            }
            $curl[CURLOPT_READFUNCTION] = function ($ch, $fd, int $length) use ($body): string {
                return $body->read($length);
            };
        }

        // Sink: always write the body via WRITEFUNCTION into a stream. The
        // default is a php://temp stream (seekable, memory-efficient); a
        // configured sink (path/resource/stream) receives the body instead.
        // CURLOPT_RETURNTRANSFER is never used — it conflicts with streaming.
        $sink = $this->prepareSink($options['sink'] ?? null);
        $curl[CURLOPT_WRITEFUNCTION] = function ($ch, string $data) use ($sink): int {
            return $sink->write($data);
        };

        // Custom per-request curl options merged over defaults.
        foreach ($options['curl'] as $option => $value) {
            $curl[$option] = $value;
        }

        // Progress callback (modern XFERINFOFUNCTION API). The callback
        // receives ($downloaded, $total, $uploaded, $uploadTotal) —
        // download-first to match the common progress-bar use case, with
        // upload values appended for callers that need them. PHP ignores
        // extra args, so 2-arg callbacks keep working unchanged.
        if (isset($options['progress'])) {
            $curl[CURLOPT_NOPROGRESS] = false;
            $curl[CURLOPT_XFERINFOFUNCTION] = function ($ch, $dlTotal, $dlNow, $ulTotal, $ulNow) use ($options): int {
                ($options['progress'])($dlNow, $dlTotal, $ulNow, $ulTotal);
                return 0;
            };
        }

        curl_setopt_array($ch, $curl);

        // Capture response headers.
        $responseHeaders = [];
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $header) use (&$responseHeaders) {
            $length = strlen($header);
            $trimmed = trim($header);
            if ($trimmed === '' || str_starts_with($trimmed, 'HTTP/')) {
                return $length;
            }

            $colon = strpos($trimmed, ':');
            if ($colon === false) {
                return $length;
            }

            $name = trim(substr($trimmed, 0, $colon));
            $value = trim(substr($trimmed, $colon + 1));
            $responseHeaders[$name][] = $value;

            return $length;
        });

        $result = curl_exec($ch);

        if ($result === false) {
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            unset($ch);

            Log::channel(self::LOG_CHANNEL)->error("cURL Error ({$errno}): {$error}");

            throw $this->createException($errno, $error, $request);
        }

        $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        unset($ch);

        // Rewind the sink so the response body is readable from the start.
        if ($sink->isSeekable()) {
            $sink->rewind();
        }

        // Build the PSR-7 response.
        $response = new Response();
        $response = $response->withStatus($statusCode);

        foreach ($responseHeaders as $name => $values) {
            $response = $response->withAddedHeader($name, $values);
        }

        $response = $response->withBody($sink);

        Log::channel(self::LOG_CHANNEL)->debug("Completed {$method} request to {$url} with status {$statusCode}");

        return $response;
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
     * Prepare the response body sink.
     *
     * Defaults to a seekable php://temp stream. A configured sink (string
     * path, resource, or StreamInterface) receives the body instead.
     *
     * @param string|resource|StreamInterface|null $sink
     */
    private function prepareSink(mixed $sink): StreamInterface
    {
        if ($sink === null) {
            return Stream::fromResource(fopen('php://temp', 'w+'));
        }

        if ($sink instanceof StreamInterface) {
            return $sink;
        }

        if (is_resource($sink)) {
            return Stream::fromResource($sink);
        }

        if (is_string($sink)) {
            $resource = fopen($sink, 'w+');
            if ($resource === false) {
                throw new \InvalidArgumentException("Unable to open sink file: {$sink}");
            }
            return Stream::fromResource($resource);
        }

        throw new \InvalidArgumentException('Sink must be a file path, resource, or StreamInterface');
    }

    /**
     * Map a cURL error to a PSR-18 exception.
     *
     * Transport-level failures (DNS, proxy, connect, timeout) map to
     * {@see NetworkException}; everything else to {@see RequestException}.
     */
    private function createException(int $errno, string $error, RequestInterface $request): RequestException
    {
        $networkErrors = [
            CURLE_COULDNT_RESOLVE_HOST,
            CURLE_COULDNT_RESOLVE_PROXY,
            CURLE_COULDNT_CONNECT,
            CURLE_OPERATION_TIMEDOUT,
            CURLE_SSL_CONNECT_ERROR,
            CURLE_RECV_ERROR,
            CURLE_SEND_ERROR,
        ];

        $message = "cURL error {$errno}: {$error}";

        if (in_array($errno, $networkErrors, true)) {
            return new NetworkException($message, $request);
        }

        return new RequestException($message, $request);
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
                throw new \InvalidArgumentException("Unknown Psr18Client config key: {$key}");
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

        if (array_key_exists('curl_options', $config)) {
            foreach (array_keys($config['curl_options']) as $option) {
                if (isset(self::CONFLICTING_CURL_OPTIONS[$option])) {
                    throw new \InvalidArgumentException(
                        'curl_options must not override ' . self::CONFLICTING_CURL_OPTIONS[$option]
                        . ' — it is managed by Psr18Client'
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
        $allowed = ['sink', 'timeout', 'verify_ssl', 'headers', 'curl', 'user_agent', 'basic_auth', 'query', 'progress'];

        foreach ($options as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                throw new \InvalidArgumentException("Unknown Psr18Client request option: {$key}");
            }
        }

        if (array_key_exists('progress', $options) && !is_callable($options['progress'])) {
            throw new \InvalidArgumentException('progress must be a callable');
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

        if (array_key_exists('curl', $options)) {
            if (!is_array($options['curl'])) {
                throw new \InvalidArgumentException('curl must be an array');
            }

            foreach (array_keys($options['curl']) as $option) {
                if (isset(self::CONFLICTING_CURL_OPTIONS[$option])) {
                    throw new \InvalidArgumentException(
                        'curl must not override ' . self::CONFLICTING_CURL_OPTIONS[$option]
                        . ' — it is managed by Psr18Client'
                    );
                }
            }
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