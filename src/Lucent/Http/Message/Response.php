<?php
declare(strict_types=1);


namespace Lucent\Http\Message;

use Lucent\Http\Message\Stream\IteratorStream;
use Lucent\Http\Message\Stream\LazyStream;
use Psr\Http\Message\ResponseInterface;
use Traversable;

/**
 * PSR-7 Response implementation with Lucent-specific convenience methods.
 *
 * Immutable — all with*() methods return a new instance.
 *
 * @final This class should not be extended in production code.
 *        Use composition or PSR-7 decorators instead.
 *        See https://github.com/Nyholm/psr7/blob/master/doc/final.md
 */
class Response extends AbstractMessage implements ResponseInterface
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
     * @throws \InvalidArgumentException when the $status is invalid or json encoding fails
     */
    public static function json(mixed $data, int $status = 200, array $headers = []): static
    {
        // Validate the status code the same way withStatus() does.
        if ($status < 100 || $status > 599) {
            throw new \InvalidArgumentException("Invalid status code: $status");
        }

        $response = new static();
        $response->statusCode = $status;
        $response->reasonPhrase = self::PHRASES[$status] ?? '';

        $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            throw new \InvalidArgumentException("Unable to JSON-encode response data: " . json_last_error_msg());
        }
        $response->setBody(Stream::fromString($payload));

        $response->withHeaderInternal('Content-Type', 'application/json; charset=utf-8');
        foreach ($headers as $name => $value) {
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
     */
    public function withJsonBody(mixed $data): static
    {
        $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            throw new \RuntimeException('Unable to JSON-encode response data: ' . json_last_error_msg());
        }
        return $this->withBody(Stream::fromString($payload))
            ->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    /**
     * Return a new response with the legacy {message, outcome, status, content} JSON envelope.
     *
     * @param array $content The response content
     * @param string $message The response message
     * @param bool $outcome Whether the request succeeded
     * @param int $status HTTP status code
     * @param array $errors Optional errors payload (e.g. exception details)
     */
    public function withJsonEnvelope(
        array $content = [],
        string $message = 'Request successfully executed.',
        bool $outcome = true,
        int $status = 200,
        array $errors = []
    ): static {
        $envelope = [
            'message' => $message,
            'outcome' => $outcome,
            'status' => $status,
            'content' => $content,
        ];

        if ($errors !== []) {
            $envelope['errors'] = $errors;
        }

        return $this->withJsonBody($envelope)->withStatus($status);
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
     * A callable produces a lazy one-shot body ({@see LazyStream}): invoked
     * once on first read, fully buffered — deferred execution, not
     * incremental. A Traversable produces true incremental streaming
     * ({@see IteratorStream}): one chunk per read.
     *
     * @param callable|Traversable $source A callable (uses LazyStream) or Traversable/Generator (uses IteratorStream)
     * @param array $headers Additional headers to set
     */
    public function withStream(callable|Traversable $source, array $headers = []): static
    {
        $body = $source instanceof Traversable
            ? new IteratorStream($source)
            : new LazyStream($source);

        $new = $this->withBody($body);
        foreach ($headers as $name => $value) {
            $new = $new->withHeader($name, $value);
        }

        return $new;
    }

    /**
     * Return a new SSE (Server-Sent Events) response.
     *
     * Sets appropriate SSE headers and wraps the source in an IteratorStream.
     *
     * Accepts any Traversable — Generator, Iterator, or IteratorAggregate
     * (e.g. LazyCollection). Each iteration yields one SSE event, flushed
     * per event (true streaming). A callable cannot be partially invoked, so
     * it would have to self-flush around the stream — use withStream() for
     * one-shot lazy bodies instead.
     *
     * @param Traversable $source A lazy iterable that yields SSE event payloads
     */
    public function withEventStream(Traversable $source): static
    {
        return $this
            ->withBody(new IteratorStream($source))
            ->withHeader('Content-Type', 'text/event-stream')
            ->withHeader('Cache-Control', 'no-cache, no-store, must-revalidate')
            ->withHeader('X-Accel-Buffering', 'no')
            ->withHeader('Connection', 'keep-alive');
    }
}
