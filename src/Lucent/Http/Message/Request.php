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
     * @return static
     */
    public function withMethod(string $method): RequestInterface
    {
        $new = clone $this;
        $new->method = strtoupper($method);
        return $new;
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

        if (! $preserveHost || ! $this->hasHeader('Host')) {
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