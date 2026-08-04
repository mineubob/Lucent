<?php

namespace Lucent\Http\Message;

use Generator;
use Lucent\Http\EventStream\EventStreamResponse;
use Lucent\Http\HttpResponse;
use Lucent\Http\Message\Stream\CallbackStream;
use Lucent\Http\Message\Stream\IteratorStream;
use Psr\Http\Message\ResponseInterface;
use Traversable;

/**
 * PSR-7 Response implementation with Lucent-specific convenience methods.
 *
 * Immutable — all with*() methods return a new instance.
 */
final class Response extends AbstractMessage implements ResponseInterface
{
    /** @var int HTTP status code */
    private int $statusCode = 200;

    /** @var string Reason phrase */
    private string $reasonPhrase = 'OK';

    /** @var array<string, string> Map of status codes to default reason phrases */
    private const PHRASES = [
        100 => 'Continue',
        101 => 'Switching Protocols',
        102 => 'Processing',
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        203 => 'Non-Authoritative Information',
        204 => 'No Content',
        205 => 'Reset Content',
        206 => 'Partial Content',
        300 => 'Multiple Choices',
        301 => 'Moved Permanently',
        302 => 'Found',
        303 => 'See Other',
        304 => 'Not Modified',
        305 => 'Use Proxy',
        307 => 'Temporary Redirect',
        308 => 'Permanent Redirect',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        402 => 'Payment Required',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        407 => 'Proxy Authentication Required',
        408 => 'Request Timeout',
        409 => 'Conflict',
        410 => 'Gone',
        411 => 'Length Required',
        412 => 'Precondition Failed',
        413 => 'Payload Too Large',
        414 => 'URI Too Long',
        415 => 'Unsupported Media Type',
        416 => 'Range Not Satisfiable',
        417 => 'Expectation Failed',
        426 => 'Upgrade Required',
        429 => 'Too Many Requests',
        451 => 'Unavailable For Legal Reasons',
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
        505 => 'HTTP Version Not Supported',
    ];

    public function __construct()
    {
        parent::__construct();
    }

    // ─── Static Factories ───────────────────────────────────────────────

    /**
     * Create a PSR-7 Response from JSON data.
     *
     * @param mixed $data Data to JSON-encode as the body
     * @param int $status HTTP status code
     * @param array $headers Additional headers
     * @return self
     */
    public static function json(mixed $data, int $status = 200, array $headers = []): self
    {
        $response = new self();
        $response->statusCode = $status;
        $response->reasonPhrase = self::PHRASES[$status] ?? '';

        $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $response->setBody(Stream::fromString($payload !== false ? $payload : '{}'));

        $response->withHeaderInternal('Content-Type', 'application/json; charset=utf-8');
        foreach ($headers as $name => $value) {
            $response->withHeaderInternal($name, $value);
        }

        return $response;
    }

    /**
     * Create a PSR-7 Response from a legacy Lucent\Http\HttpResponse.
     *
     * Handles HttpResponse, JsonResponse, RedirectResponse, EventStreamResponse.
     *
     * @param HttpResponse $legacy The legacy response to convert
     * @return self
     * @deprecated Use the new PSR-7 Response API directly
     */
    public static function fromLegacy(HttpResponse $legacy): self
    {
        trigger_error(
            'Lucent\Http\Message\Response::fromLegacy() is deprecated. Return a PSR-7 Response directly instead.',
            E_USER_DEPRECATED
        );

        $response = new self();
        $response->statusCode = $legacy->status();
        $response->reasonPhrase = self::PHRASES[$legacy->status()] ?? '';

        // Handle EventStreamResponse — wrap its callback in a CallbackStream
        if ($legacy instanceof EventStreamResponse) {
            $callback = (new \ReflectionClass($legacy))
                ->getProperty('callback')
                ->getValue($legacy);

            $response->setBody(new CallbackStream($callback));
            $response->withHeaderInternal('Content-Type', 'text/event-stream');
            $response->withHeaderInternal('Cache-Control', 'no-cache, no-store, must-revalidate');
            $response->withHeaderInternal('X-Accel-Buffering', 'no');
            $response->withHeaderInternal('Connection', 'keep-alive');
            return $response;
        }

        // Generic HttpResponse (handles HttpResponse, JsonResponse, RedirectResponse)
        $body = $legacy->body();
        if ($body !== null) {
            $response->setBody(Stream::fromString($body));
        }

        foreach ($legacy->headers() as $name => $value) {
            $response->withHeaderInternal($name, $value);
        }

        return $response;
    }

    // ─── ResponseInterface ──────────────────────────────────────────────

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function withStatus(int $code, string $reasonPhrase = ''): ResponseInterface
    {
        if ($code < 100 || $code > 599) {
            throw new \InvalidArgumentException("Invalid status code: $code");
        }

        $new = clone $this;
        $new->statusCode = $code;
        $new->reasonPhrase = $reasonPhrase !== ''
            ? $reasonPhrase
            : (self::PHRASES[$code] ?? '');
        return $new;
    }

    public function getReasonPhrase(): string
    {
        return $this->reasonPhrase;
    }

    // ─── Lucent Convenience Methods ─────────────────────────────────────

    /**
     * Return a new response with a JSON-encoded body.
     *
     * @param mixed $data Data to JSON-encode
     * @param int $status Optional status code (defaults to current)
     */
    public function withJsonBody(mixed $data, ?int $status = null): static
    {
        $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $new = $this->withBody(Stream::fromString($payload !== false ? $payload : '{}'))
            ->withHeader('Content-Type', 'application/json; charset=utf-8');

        if ($status !== null) {
            $new = $new->withStatus($status);
        }

        return $new;
    }

    /**
     * Return a new response with the legacy {message, outcome, status, content} JSON envelope.
     *
     * @param array $content The response content
     * @param string $message The response message
     * @param bool $outcome Whether the request succeeded
     * @param int $status HTTP status code
     */
    public function withJsonEnvelope(
        array $content = [],
        string $message = 'Request successfully executed.',
        bool $outcome = true,
        int $status = 200
    ): static {
        $envelope = [
            'message' => $message,
            'outcome' => $outcome,
            'status' => $status,
            'content' => $content,
        ];

        $new = $this->withJsonBody($envelope, $status);
        return $new;
    }

    /**
     * Return a new redirect response.
     *
     * @param string $url The URL to redirect to
     * @param int $status HTTP status code (default 302)
     */
    public function withRedirect(string $url, int $status = 302): static
    {
        $new = $this->withStatus($status)
            ->withHeader('Location', $url)
            ->withBody(Stream::fromString("Redirecting to {$url}"));

        if (! $new->hasHeader('Content-Type')) {
            $new = $new->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }

        return $new;
    }

    /**
     * Return a new response with a streaming body.
     *
     * @param callable|Traversable $source A callable (uses CallbackStream) or Traversable/Generator (uses IteratorStream)
     * @param array $headers Additional headers to set
     */
    public function withStream(callable|Traversable $source, array $headers = []): static
    {
        $body = $source instanceof Traversable
            ? new IteratorStream($source)
            : new CallbackStream($source);

        $new = $this->withBody($body);
        foreach ($headers as $name => $value) {
            $new = $new->withHeader($name, $value);
        }

        return $new;
    }

    /**
     * Return a new SSE (Server-Sent Events) response.
     *
     * Sets appropriate SSE headers and wraps the source in a streaming stream.
     *
     * @param callable|Generator $source A callable or Generator that yields/produces SSE events
     */
    public function withEventStream(callable|Generator $source): static
    {
        $body = $source instanceof Generator
            ? new IteratorStream($source)
            : new CallbackStream($source);

        return $this
            ->withBody($body)
            ->withHeader('Content-Type', 'text/event-stream')
            ->withHeader('Cache-Control', 'no-cache, no-store, must-revalidate')
            ->withHeader('X-Accel-Buffering', 'no')
            ->withHeader('Connection', 'keep-alive');
    }
}
