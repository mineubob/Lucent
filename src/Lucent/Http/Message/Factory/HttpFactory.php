<?php

namespace Lucent\Http\Message\Factory;

use Lucent\Http\Message\Request;
use Lucent\Http\Message\Response;
use Lucent\Http\Message\ServerRequest;
use Lucent\Http\Message\Stream;
use Lucent\Http\Message\UploadedFile;
use Lucent\Http\Message\Uri;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\UriInterface;

/**
 * Single PSR-17 factory implementing all 6 factory interfaces.
 *
 * Per PSR-17, creates bare HTTP message objects — no JSON/redirect conveniences.
 * Those are provided by LucentResponseFactory (Lucent-specific).
 */
final class HttpFactory implements
    RequestFactoryInterface,
    \Psr\Http\Message\ResponseFactoryInterface,
    \Psr\Http\Message\ServerRequestFactoryInterface,
    \Psr\Http\Message\StreamFactoryInterface,
    \Psr\Http\Message\UploadedFileFactoryInterface,
    \Psr\Http\Message\UriFactoryInterface
{
    // ─── RequestFactoryInterface ────────────────────────────────────────

    /**
     * Create a new request.
     *
     * @param string $method HTTP method (e.g., GET, POST)
     * @param string|UriInterface $uri URI string or object
     * @return RequestInterface
     */
    public function createRequest(string $method, $uri): RequestInterface
    {
        $uriString = $uri instanceof UriInterface ? $uri : Uri::fromString($uri);
        return new Request($method, $uriString);
    }

    // ─── ResponseFactoryInterface ───────────────────────────────────────

    /**
     * Create a new response.
     *
     * @param int $code HTTP status code
     * @param string $reasonPhrase Reason phrase; empty string uses the default for the status code
     * @return ResponseInterface
     */
    public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface
    {
        return new Response()
            ->withStatus($code, $reasonPhrase);
    }

    // ─── ServerRequestFactoryInterface ──────────────────────────────────

    /**
     * Create a new server request.
     *
     * @param string $method HTTP method (e.g., GET, POST)
     * @param string|UriInterface $uri URI string or object
     * @param array $serverParams Server parameters ($_SERVER)
     * @return ServerRequestInterface
     */
    public function createServerRequest(string $method, $uri, array $serverParams = []): ServerRequestInterface
    {
        return new ServerRequest($method, $uri instanceof UriInterface ? $uri : Uri::fromString((string) $uri), $serverParams);
    }

    // ─── StreamFactoryInterface ─────────────────────────────────────────

    /**
     * Create a new stream from a string.
     *
     * The stream is created with a temporary resource.
     *
     * @param string $content String content with which to populate the stream
     * @return StreamInterface
     */
    public function createStream(string $content = ''): StreamInterface
    {
        $resource = fopen('php://temp', 'w+');
        if ($resource === false) {
            throw new \RuntimeException('Unable to create temporary stream');
        }
        if ($content !== '') {
            fwrite($resource, $content);
            rewind($resource);
        }
        return Stream::fromResource($resource);
    }

    /**
     * Create a stream from a file.
     *
     * @param string $filename Filesystem path or stream URI
     * @param string $mode Mode with which to open the file (see fopen)
     * @return StreamInterface
     * @throws \RuntimeException If the file cannot be opened
     * @throws \InvalidArgumentException If the mode is invalid
     */
    public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
    {
        // Mirror PHP's fopen mode grammar: a base letter (r/w/a/x/c) followed
        // by any combination of the +, b, e, and t flags (e.g. rb, r+b, wb).
        if (!preg_match('/^[rwaxc][+bet]*$/', $mode)) {
            throw new \InvalidArgumentException("Invalid file mode: $mode");
        }

        $resource = @fopen($filename, $mode);
        if ($resource === false) {
            $error = error_get_last();
            $reason = $error['message'] ?? 'unknown error';
            throw new \RuntimeException("Unable to open file: $filename ($reason)");
        }
        return Stream::fromResource($resource);
    }

    /**
     * Create a stream from an existing resource.
     *
     * The stream MUST be readable and may be writable.
     *
     * @param resource $resource PHP stream resource
     * @return StreamInterface
     * @throws \InvalidArgumentException If the resource is not a valid readable stream
     */
    public function createStreamFromResource($resource): StreamInterface
    {
        if (!is_resource($resource)) {
            throw new \InvalidArgumentException('Invalid stream resource provided');
        }

        $stream = Stream::fromResource($resource);
        if (!$stream->isReadable()) {
            throw new \InvalidArgumentException('Stream resource must be readable');
        }

        return $stream;
    }

    // ─── UploadedFileFactoryInterface ───────────────────────────────────

    /**
     * Create a new uploaded file.
     *
     * @param StreamInterface $stream Stream containing the uploaded file data
     * @param int|null $size File size in bytes
     * @param int $error Upload error code (UPLOAD_ERR_* constant)
     * @param string|null $clientFilename Original client filename
     * @param string|null $clientMediaType Original client media type
     * @return UploadedFileInterface
     * @throws \InvalidArgumentException If the stream is not readable (enforced
     *     by the UploadedFile constructor)
     */
    public function createUploadedFile(
        StreamInterface $stream,
        ?int $size = null,
        int $error = \UPLOAD_ERR_OK,
        ?string $clientFilename = null,
        ?string $clientMediaType = null
    ): UploadedFileInterface {
        // If a size is not provided it is determined by
        // checking the size of the stream.
        if ($size === null) {
            $size = $stream->getSize();
        }

        return new UploadedFile($stream, $size, $error, $clientFilename, $clientMediaType);
    }

    // ─── UriFactoryInterface ────────────────────────────────────────────

    /**
     * Create a new URI from a string.
     *
     * @param string $uri URI string (e.g., https://example.com/path?query=1)
     * @return UriInterface
     * @throws \InvalidArgumentException If the URI cannot be parsed
     */
    public function createUri(string $uri = ''): UriInterface
    {
        return Uri::fromString($uri);
    }
}
