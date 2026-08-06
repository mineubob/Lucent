<?php

namespace Lucent\Http\Client\Handler\Concerns;

use Lucent\Http\Message\Response;
use Lucent\Http\Message\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Shared response-body handling for transport handlers.
 *
 * Both the cURL and stream transports write the response body into a sink
 * and assemble the final PSR-7 response identically, so the logic lives here.
 */
trait HandlesResponseBodies
{
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
     * Parse an HTTP status line into its components.
     *
     * @param string $line e.g. "HTTP/1.1 200 OK"
     * @return array{0: string, 1: int, 2: string} [version, status, reason]
     */
    private function parseStatusLine(string $line): array
    {
        $parts = explode(' ', trim($line), 3);
        $version = isset($parts[0]) ? ltrim($parts[0], 'HTTP/') : '1.1';
        $status = isset($parts[1]) ? (int) $parts[1] : 200;
        $reason = $parts[2] ?? '';

        return [$version, $status, $reason];
    }

    /**
     * Assemble a complete PSR-7 response.
     *
     * Sets protocol version, status, reason phrase, headers, and body — the
     * same shape regardless of which transport produced it.
     *
     * @param array<string, string[]> $headers Headers as [name => values]
     */
    private function buildResponse(
        string $version,
        int $statusCode,
        string $reasonPhrase,
        array $headers,
        StreamInterface $body
    ): ResponseInterface {
        $response = new Response();
        $response = $response
            ->withProtocolVersion($version)
            ->withStatus($statusCode, $reasonPhrase);

        foreach ($headers as $name => $values) {
            $response = $response->withAddedHeader($name, $values);
        }

        return $response->withBody($body);
    }
}
