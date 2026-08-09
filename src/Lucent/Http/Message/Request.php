<?php

namespace Lucent\Http\Message;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;

/**
 * PSR-7 Request implementation (client-side request, not server request).
 *
 * For server-side requests, use ServerRequest.
 */
final class Request extends AbstractMessage implements RequestInterface
{
    /** @var string HTTP method */
    private string $method = 'GET';

    /** @var UriInterface */
    private UriInterface $uri;

    /** @var string|null Request target override */
    private ?string $requestTarget = null;

    public function __construct(string $method = 'GET', ?UriInterface $uri = null)
    {
        parent::__construct();
        $this->method = strtoupper($method);
        $this->uri = $uri ?? Uri::fromString('/');

        // A request built with a URI should carry that URI's host as its
        // Host header unless one was already provided.
        $host = $this->uri->getHost();
        if ($host !== '') {
            $port = $this->uri->getPort();
            $this->withHeaderInternal('Host', $port !== null ? $host . ':' . $port : $host);
        }
    }

    public function getRequestTarget(): string
    {
        if ($this->requestTarget !== null) {
            return $this->requestTarget;
        }

        $target = $this->uri->getPath();
        $query = $this->uri->getQuery();
        if ($query !== '') {
            $target .= '?' . $query;
        }

        return $target ?: '/';
    }

    /**
     * @return static
     */
    public function withRequestTarget(string $requestTarget): RequestInterface
    {
        $new = clone $this;
        $new->requestTarget = $requestTarget;
        return $new;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * HTTP method names are case-sensitive; The given string is
     * stored as-provided.
     *
     * @throws \InvalidArgumentException for invalid HTTP methods
     * @return static
     */
    public function withMethod(string $method): RequestInterface
    {
        self::assertValidMethod($method);

        $new = clone $this;
        $new->method = $method;
        return $new;
    }

    /**
     * Assert that an HTTP method is a valid token (RFC 7230 §3.1.1).
     *
     * @throws \InvalidArgumentException
     */
    private static function assertValidMethod(string $method): void
    {
        if ($method === '' || !preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/', $method)) {
            throw new \InvalidArgumentException("Invalid HTTP method: '$method'");
        }
    }

    public function getUri(): UriInterface
    {
        return $this->uri;
    }

    /**
     * @return static
     */
    public function withUri(UriInterface $uri, bool $preserveHost = false): RequestInterface
    {
        $new = clone $this;
        $new->uri = $uri;

        // With $preserveHost=true the Host header is only kept when it is
        // present AND non-empty; a missing or empty Host header is updated
        // from the new URI.
        if (! $preserveHost || $this->getHeaderLine('Host') === '') {
            $host = $uri->getHost();
            if ($host !== '') {
                $port = $uri->getPort();
                $hostValue = $port !== null ? $host . ':' . $port : $host;
                $new = $new->withHeader('Host', $hostValue);
            }
        }

        return $new;
    }
}