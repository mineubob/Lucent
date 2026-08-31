<?php
declare(strict_types=1);


namespace Lucent\Http\Message\Factory;

use Lucent\Http\Message\Response;
use Psr\Http\Message\ResponseInterface;
use Traversable;

/**
 * Lucent-specific convenience factory for creating common response types.
 *
 * NOT a PSR-17 interface — this is a Lucent convenience layer on top of PSR-7.
 * PSR-17 factories (Psr17Factory) create only bare messages; this factory
 * offers JSON, redirect, SSE, and streaming conveniences.
 *
 * Users can also use the fluent with*() methods on Response directly.
 */
final class LucentResponseFactory
{
    /**
     * Create a JSON response.
     *
     * @param mixed $data Data to JSON-encode as the body
     * @param int $status HTTP status code
     * @return ResponseInterface
     */
    public function createJsonResponse(mixed $data, int $status = 200): ResponseInterface
    {
        return Response::json($data, $status);
    }

    /**
     * Create a JSON response with the legacy {message, outcome, status, content} envelope.
     *
     * @param array $content The response content
     * @param string $message The response message
     * @param bool $outcome Whether the request succeeded
     * @param int $status HTTP status code
     * @return ResponseInterface
     */
    public function createJsonEnvelopeResponse(
        array $content = [],
        string $message = 'Request successfully executed.',
        bool $outcome = true,
        int $status = 200
    ): ResponseInterface {
        $response = new Response();
        return $response->withJsonEnvelope($content, $message, $outcome, $status);
    }

    /**
     * Create a redirect response.
     *
     * @param string $url The URL to redirect to
     * @param int $status HTTP status code (default 302)
     * @return ResponseInterface
     */
    public function createRedirectResponse(string $url, int $status = 302): ResponseInterface
    {
        $response = new Response();
        return $response->withRedirect($url, $status);
    }

    /**
     * Create an SSE (Server-Sent Events) response.
     *
     * @param Traversable $source A lazy iterable (Generator, Iterator, or
     *                            IteratorAggregate e.g. LazyCollection)that
     *                            yields SSE event payloads
     * @return ResponseInterface
     */
    public function createEventStreamResponse(Traversable $source): ResponseInterface
    {
        $response = new Response();
        return $response->withEventStream($source);
    }

    /**
     * Create a generic streaming response.
     *
     * @param callable|Traversable $source A callable or Traversable/Generator
     * @param array $headers Additional headers
     * @return ResponseInterface
     */
    public function createStreamResponse(callable|Traversable $source, array $headers = []): ResponseInterface
    {
        $response = new Response();
        return $response->withStream($source, $headers);
    }
}
