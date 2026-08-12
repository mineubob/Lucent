<?php

namespace Lucent\Http\Client\Handler;

use Lucent\Facades\Log;
use Lucent\Http\Client\Exception\NetworkException;
use Lucent\Http\Client\Handler\Concerns\HandlesResponseBodies;
use Lucent\Http\Client\Client;
use Lucent\Http\Message\Stream;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * PHP stream-wrapper transport handler.
 *
 * Honors the `stream => true` option by returning the LIVE transport stream
 * as the response body (true incremental reads). Without `stream => true`,
 * the body is drained into a sink (php://temp or configured) before
 * returning, matching the buffered semantics of the CurlHandler.
 *
 * Requires allow_url_fopen. The `curl` option is rejected (this handler
 * ignores cURL options), mirroring Guzzle's StreamHandler.
 */
final class StreamHandler implements HandlerInterface
{
    use HandlesResponseBodies;

    /** @var string Log channel used by the client */
    private const LOG_CHANNEL = 'lucent.http';

    public function send(RequestInterface $request, array $options): ResponseInterface
    {
        $url = (string) $request->getUri();
        $method = $request->getMethod();

        Log::channel(self::LOG_CHANNEL)->info("Starting {$method} request to {$url}");

        // Clear any stale headers from a previous/failed request so we never
        // read them as this response's headers.
        http_clear_last_response_headers();

        $context = $this->buildContext($request, $options);

        $resource = @fopen($url, 'r', false, $context);

        if ($resource === false) {
            $error = error_get_last()['message'] ?? 'Unknown stream error';
            Log::channel(self::LOG_CHANNEL)->error("Stream Error: {$error}");
            throw new NetworkException("Unable to open stream: {$error}", $request);
        }

        // Idle-read timeout on the socket.
        $timeout = (int) ($options['timeout'] ?? 30);
        stream_set_timeout($resource, $timeout);

        // Read the response headers. http_get_last_response_headers() was
        // added in PHP 8.4 (the framework's minimum) — the magic
        // $http_response_header variable is deprecated in function scope.
        $rawHeaders = http_get_last_response_headers();

        [$version, $status, $reason] = $this->parseHeaders($rawHeaders ?? []);

        $streaming = !empty($options['stream']);

        if ($streaming) {
            // Live body: the caller reads incrementally; do NOT close.
            $body = Stream::fromResource($resource);
        } else {
            $sink = $this->prepareSink($options['sink'] ?? null);
            $this->drain($resource, $sink);
            fclose($resource);
            if ($sink->isSeekable()) {
                $sink->rewind();
            }
            $body = $sink;
        }

        Log::channel(self::LOG_CHANNEL)->debug("Completed {$method} request to {$url} with status {$status}");

        return $this->buildResponse($version, $status, $reason, $this->normalizeHeaders($rawHeaders ?? []), $body);
    }

    /**
     * Validate handler-specific options.
     *
     * The stream handler cannot honor cURL options or a progress callback.
     *
     * @param array<string, mixed> $options Merged per-request options
     * @throws \InvalidArgumentException
     */
    public function validateOptions(array $options): void
    {
        if (!empty($options['curl'])) {
            throw new \InvalidArgumentException(
                'Passing the "curl" request option to the stream handler is not supported because the stream handler ignores cURL options.'
            );
        }

        if (array_key_exists('progress', $options)) {
            throw new \InvalidArgumentException(
                'Passing the "progress" request option to the stream handler is not supported because the stream handler has no progress callback.'
            );
        }
    }

    /**
     * @param array<string, mixed> $options
     * @return resource
     */
    private function buildContext(RequestInterface $request, array $options)
    {
        // Headers: defaults + per-request already merged; request headers win.
        $headers = $options['headers'] ?? [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[$name] = implode(', ', $values);
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = "{$name}: {$value}";
        }

        // Basic auth → Authorization header.
        $basicAuth = $options['basic_auth'] ?? null;
        if ($basicAuth !== null) {
            $headerLines[] = 'Authorization: Basic ' . base64_encode($basicAuth[0] . ':' . $basicAuth[1]);
        }

        $context = [
            'http' => [
                'method' => $request->getMethod(),
                'protocol_version' => $request->getProtocolVersion(),
                'ignore_errors' => true,
                'follow_location' => 1,
                'max_redirects' => 10,
                'timeout' => (float) ($options['timeout'] ?? 30),
                'user_agent' => $options['user_agent'] ?? Client::defaultUserAgent(),
                'header' => $headerLines,
            ],
        ];

        // Request body → http context 'content'. When the size is known,
        // send Content-Length so the server knows when the body is complete.
        $body = $request->getBody();
        $bodySize = $body->getSize();
        if ($bodySize === null || $bodySize > 0) {
            $context['http']['content'] = (string) $body;
            if ($bodySize !== null && !$request->hasHeader('Content-Length')) {
                $headerLines[] = 'Content-Length: ' . $bodySize;
                $context['http']['header'] = $headerLines;
            }
        }

        // SSL verification.
        if (empty($options['verify_ssl'])) {
            $context['ssl'] = [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ];
        }

        return stream_context_create($context);
    }

    /**
     * Parse the FINAL status line from the raw headers.
     *
     * With follow_location enabled or 1xx interim responses, the raw header
     * list contains multiple status blocks — the last HTTP/ line belongs to
     * the final response.
     *
     * @param list<string> $rawHeaders Raw header lines (incl. status lines)
     * @return array{0: string, 1: int, 2: string} [version, status, reason]
     */
    private function parseHeaders(array $rawHeaders): array
    {
        $lastStatusLine = null;
        foreach ($rawHeaders as $line) {
            if (str_starts_with(trim($line), 'HTTP/')) {
                $lastStatusLine = $line;
            }
        }

        return $lastStatusLine !== null
            ? $this->parseStatusLine($lastStatusLine)
            : ['1.1', 200, ''];
    }

    /**
     * Normalize headers from the FINAL response block only.
     *
     * Only header lines after the last HTTP/ status line belong to the final
     * response; earlier blocks are redirects or 1xx interim responses.
     *
     * @param list<string> $rawHeaders Raw header lines (incl. status lines)
     * @return array<string, string[]> Headers as [name => values]
     */
    private function normalizeHeaders(array $rawHeaders): array
    {
        // Find the offset of the last status line.
        $lastStatusOffset = -1;
        foreach ($rawHeaders as $i => $line) {
            if (str_starts_with(trim($line), 'HTTP/')) {
                $lastStatusOffset = $i;
            }
        }

        $headers = [];
        foreach (array_slice($rawHeaders, $lastStatusOffset + 1) as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            $colon = strpos($trimmed, ':');
            if ($colon === false) {
                continue;
            }

            $name = trim(substr($trimmed, 0, $colon));
            $value = trim(substr($trimmed, $colon + 1));
            $headers[$name][] = $value;
        }

        return $headers;
    }

    private function drain($resource, StreamInterface $sink): void
    {
        while (!feof($resource)) {
            $chunk = fread($resource, 8192);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $sink->write($chunk);
        }
    }
}
