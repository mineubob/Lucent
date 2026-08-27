<?php

namespace Lucent\Http\Client\Handler;

use Lucent\Facades\Log;
use Lucent\Http\Client\Exception\NetworkException;
use Lucent\Http\Client\Exception\RequestException;
use Lucent\Http\Client\Handler\Concerns\HandlesResponseBodies;
use Lucent\Http\Client\Client;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * cURL-backed transport handler — the default handler for Client.
 *
 * This handler uses cURL and buffers the full response body before returning
 * (via a WRITEFUNCTION into a sink). It accepts the `stream => true` option
 * but does NOT stream incrementally — the body is still fully downloaded
 * before send() returns. Use the StreamHandler
 * for true incremental streaming.
 */
final class CurlHandler implements HandlerInterface
{
    use HandlesResponseBodies;

    /** @var string Log channel used by the client */
    private const LOG_CHANNEL = 'lucent.http';

    /**
     * cURL options that conflict with the client's own handling and must not
     * be set via `curl_options` / the `curl` request option.
     *
     * @var array<int, string>
     */
    public const CONFLICTING_CURL_OPTIONS = [
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

    public function send(RequestInterface $request, array $options): ResponseInterface
    {
        $url = (string) $request->getUri();
        $method = $request->getMethod();

        Log::channel(self::LOG_CHANNEL)->info("Starting {$method} request to {$url}");

        $ch = curl_init();
        if ($ch === false) {
            throw new RequestException('Unable to initialize cURL', $request);
        }

        $curl = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => $options['timeout'] ?? 30,
            CURLOPT_USERAGENT => $options['user_agent'] ?? Client::defaultUserAgent(),
            CURLOPT_SSL_VERIFYPEER => $options['verify_ssl'] ?? true,
            CURLOPT_SSL_VERIFYHOST => ($options['verify_ssl'] ?? true) ? 2 : 0,
        ];

        // Headers: defaults + per-request already merged; request headers win.
        $headers = $options['headers'] ?? [];
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
        $basicAuth = $options['basic_auth'] ?? null;
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

        // Optional response-size cap (bytes). When exceeded, abort the
        // transfer to prevent unbounded memory/disk exhaustion (zip-bomb /
        // endless-body DoS). CURLOPT_MAXFILESIZE only limits the declared
        // Content-Length, so we enforce the cap in the write callback too.
        $maxResponseSize = $options['max_response_size'] ?? null;
        $received = 0;
        $curl[CURLOPT_WRITEFUNCTION] = function ($ch, string $data) use ($sink, &$received, $maxResponseSize): int {
            if ($maxResponseSize !== null) {
                $received += strlen($data);
                if ($received > $maxResponseSize) {
                    return 0; // signal abort to libcurl
                }
            }
            return $sink->write($data);
        };

        // Custom curl options merged over defaults (validated above).
        foreach ($options['curl'] ?? [] as $option => $value) {
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

        // Capture response headers + the status line.
        $responseHeaders = [];
        $statusLine = '';
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $header) use (&$responseHeaders, &$statusLine) {
            $length = strlen($header);
            $trimmed = trim($header);
            if ($trimmed === '') {
                return $length;
            }

            if (str_starts_with($trimmed, 'HTTP/')) {
                $statusLine = $trimmed;
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

        [$version, $status, $reason] = $this->parseStatusLine($statusLine !== '' ? $statusLine : "HTTP/1.1 {$statusCode}");

        Log::channel(self::LOG_CHANNEL)->debug("Completed {$method} request to {$url} with status {$statusCode}");

        return $this->buildResponse($version, $status, $reason, $responseHeaders, $sink);
    }

    /**
     * Map a cURL error to a PSR-18 exception.
     *
     * Transport-level failures (DNS, proxy, connect, timeout) map to
     * {@see NetworkException}; everything else to {@see RequestException}.
     */
    private function createException(int $errno, string $error, RequestInterface $request): \Psr\Http\Client\ClientExceptionInterface
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
     * Validate handler-specific options.
     *
     * @param array<string, mixed> $options Merged per-request options
     * @throws \InvalidArgumentException
     */
    public function validateOptions(array $options): void
    {
        if (array_key_exists('progress', $options) && !is_callable($options['progress'])) {
            throw new \InvalidArgumentException('progress must be a callable');
        }

        $this->assertNoConflictingCurlOptions($options['curl'] ?? []);
    }

    /**
     * @param array<int, mixed> $curlOptions
     */
    private function assertNoConflictingCurlOptions(array $curlOptions): void
    {
        foreach (array_keys($curlOptions) as $option) {
            if (isset(self::CONFLICTING_CURL_OPTIONS[$option])) {
                throw new \InvalidArgumentException(
                    'curl must not override ' . self::CONFLICTING_CURL_OPTIONS[$option]
                    . ' — it is managed by the client transport'
                );
            }
        }
    }
}
