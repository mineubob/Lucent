<?php
declare(strict_types=1);


namespace Lucent\Http\Message;

use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Shared implementation for PSR-7 MessageInterface (both Request and Response).
 *
 * Handles protocol version, headers (string[][]), and body (StreamInterface).
 */
abstract class AbstractMessage implements MessageInterface
{
    /** @var string HTTP protocol version */
    protected string $protocolVersion = '1.1';

    /** @var array<string, string[]> Headers as [name => [value, ...]] */
    protected array $headers = [];

    /** @var array<string, string> Lowercased header name map */
    protected array $headerNames = [];

    /** @var StreamInterface|null */
    private ?StreamInterface $body = null;

    protected function __construct()
    {
    }

    // ─── Protocol Version ───────────────────────────────────────────────

    public function getProtocolVersion(): string
    {
        return $this->protocolVersion;
    }

    /**
     * @return static
     */
    public function withProtocolVersion(string $version): MessageInterface
    {
        $new = clone $this;
        $new->protocolVersion = $version;
        return $new;
    }

    // ─── Headers ────────────────────────────────────────────────────────

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headerNames[strtolower($name)]);
    }

    public function getHeader(string $name): array
    {
        $lower = strtolower($name);
        if (!isset($this->headerNames[$lower])) {
            return [];
        }
        $originalName = $this->headerNames[$lower];
        return $this->headers[$originalName];
    }

    public function getHeaderLine(string $name): string
    {
        $values = $this->getHeader($name);
        if (empty($values)) {
            return '';
        }
        return implode(', ', $values);
    }

    /**
     * @return static
     */
    public function withHeader(string $name, $value): MessageInterface
    {
        $this->assertHeaderName($name);
        $this->assertHeaderValue($value);

        $new = clone $this;
        $normalized = $this->normalizeHeaderName($name);
        $lower = strtolower($name);

        // Remove old header if present
        if (isset($new->headerNames[$lower])) {
            unset($new->headers[$new->headerNames[$lower]]);
        }

        $new->headerNames[$lower] = $normalized;
        $new->headers[$normalized] = is_array($value) ? array_map([$this, 'sanitizeHeaderValue'], $value) : [$this->sanitizeHeaderValue($value)];
        return $new;
    }

    /**
     * @return static
     */
    public function withAddedHeader(string $name, $value): MessageInterface
    {
        $this->assertHeaderName($name);
        $this->assertHeaderValue($value);

        $new = clone $this;
        $normalized = $this->normalizeHeaderName($name);
        $lower = strtolower($name);

        $sanitized = is_array($value) ? array_map([$this, 'sanitizeHeaderValue'], $value) : [$this->sanitizeHeaderValue($value)];

        if (isset($new->headerNames[$lower])) {
            $existingName = $new->headerNames[$lower];
            $new->headers[$existingName] = array_merge($new->headers[$existingName], $sanitized);
        } else {
            $new->headerNames[$lower] = $normalized;
            $new->headers[$normalized] = $sanitized;
        }

        return $new;
    }

    /**
     * @return static
     */
    public function withoutHeader(string $name): MessageInterface
    {
        $lower = strtolower($name);
        if (!isset($this->headerNames[$lower])) {
            return $this;
        }

        $new = clone $this;
        $originalName = $new->headerNames[$lower];
        unset($new->headers[$originalName]);
        unset($new->headerNames[$lower]);
        return $new;
    }

    // ─── Body ───────────────────────────────────────────────────────────

    public function getBody(): StreamInterface
    {
        if ($this->body === null) {
            $this->body = Stream::fromString('');
        }
        return $this->body;
    }

    /**
     * @param StreamInterface $body Body
     * @throws \InvalidArgumentException When the body is not valid (enforced
     *     by the StreamInterface type hint — non-stream bodies are rejected
     *     by the type system before this method executes)
     * @return static
     */
    public function withBody(StreamInterface $body): MessageInterface
    {
        $new = clone $this;
        $new->body = $body;
        return $new;
    }

    // ─── Internal Helpers ───────────────────────────────────────────────

    /**
     * Set the body during construction.
     */
    protected function setBody(StreamInterface $body): void
    {
        $this->body = $body;
    }

    /**
     * Set headers during construction.
     */
    protected function setHeaders(array $headers): void
    {
        foreach ($headers as $name => $value) {
            $this->withHeaderInternal($name, $value);
        }
    }

    /**
     * Add a single header during construction (mutates, no clone).
     */
    protected function withHeaderInternal(string $name, $value): void
    {
        $normalized = $this->normalizeHeaderName($name);
        $lower = strtolower($name);

        if (isset($this->headerNames[$lower])) {
            unset($this->headers[$this->headerNames[$lower]]);
        }

        $this->headerNames[$lower] = $normalized;
        $this->headers[$normalized] = is_array($value) ? $value : [$value];
    }

    /**
     * Normalize a header name to Title-Case-With-Dashes.
     *
     * Normalizing gives consistent getHeaders() keys regardless of the
     * casing callers used; lookup stays case-insensitive via $headerNames.
     *
     * @param string $name The raw header name
     * @return string The normalized header name
     */
    private function normalizeHeaderName(string $name): string
    {
        return str_replace(' ', '-', ucwords(str_replace('-', ' ', $name)));
    }

    /**
     * Assert that a header name is valid.
     *
     * @param string $name The header name to validate
     * @throws \InvalidArgumentException
     */
    private function assertHeaderName(string $name): void
    {
        if ($name === '' || $name === null) {
            throw new \InvalidArgumentException('Header name must not be empty');
        }

        if (preg_match('/[\r\n]/', $name)) {
            throw new \InvalidArgumentException('Header name must not contain CR or LF characters');
        }

        if (!preg_match('/^[a-zA-Z0-9!#$%&\'*+\-.\^_`|~]+$/', $name)) {
            throw new \InvalidArgumentException("Invalid header name: '$name'");
        }
    }

    /**
     * Assert that a header value is valid.
     *
     * @param string|string[] $value The header value(s) to validate
     * @throws \InvalidArgumentException
     */
    private function assertHeaderValue(string|array $value): void
    {
        $values = is_array($value) ? $value : [$value];
        foreach ($values as $v) {
            if (!is_string($v)) {
                throw new \InvalidArgumentException('Header value must be a string or array of strings');
            }
            if (preg_match('/[\r\n]/', $v)) {
                throw new \InvalidArgumentException('Header value must not contain CR or LF characters');
            }
        }
    }

    /**
     * Strip CR/LF from a header value.
     *
     * @param string $value The header value to sanitize
     * @return string The sanitized header value
     */
    private function sanitizeHeaderValue(string $value): string
    {
        return str_replace(["\r", "\n"], '', $value);
    }
}