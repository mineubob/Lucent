<?php

namespace Lucent\Http\Message;

use Psr\Http\Message\UriInterface;

/**
 * PSR-7 URI implementation.
 *
 * Parses and represents URIs per RFC 3986.
 */
final class Uri implements UriInterface
{
    private string $scheme = '';
    private string $userInfo = '';
    private string $host = '';
    private ?int $port = null;
    private string $path = '';
    private string $query = '';
    private string $fragment = '';

    private const STANDARD_PORTS = [
        'http' => 80,
        'https' => 443,
    ];

    /**
     * Private constructor — use fromString() or fromGlobals() instead.
     */
    private function __construct()
    {
    }

    /**
     * Create a URI from a string.
     *
     * @param string $uri The URI string to parse
     * @return self
     * @throws \InvalidArgumentException If the URI cannot be parsed
     */
    public static function fromString(string $uri): self
    {
        $parts = parse_url($uri);
        if ($parts === false) {
            throw new \InvalidArgumentException("Unable to parse URI: $uri");
        }

        $instance = new self();
        $instance->scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : '';
        $instance->host = $parts['host'] ?? '';
        $instance->port = isset($parts['port']) ? (int) $parts['port'] : null;
        $instance->path = $parts['path'] ?? '';
        $instance->query = $parts['query'] ?? '';
        $instance->fragment = $parts['fragment'] ?? '';
        $instance->userInfo = $parts['user'] ?? '';

        if (isset($parts['pass'])) {
            $instance->userInfo .= ':' . $parts['pass'];
        }

        return $instance;
    }

    /**
     * Create a URI from PHP superglobals ($_SERVER).
     *
     * @param array $server Typically $_SERVER
     * @return self
     */
    public static function fromGlobals(array $server): self
    {
        $instance = new self();

        // Scheme
        $https = $server['HTTPS'] ?? '';
        $instance->scheme = (!empty($https) && $https !== 'off') ? 'https' : 'http';

        // Host
        $host = $server['HTTP_HOST'] ?? $server['SERVER_NAME'] ?? '';
        if (str_contains($host, ':')) {
            [$instance->host, $portPart] = explode(':', $host, 2);
            $instance->port = (int) $portPart;
        } else {
            $instance->host = $host;
        }

        // Port
        $serverPort = $server['SERVER_PORT'] ?? null;
        if ($serverPort !== null && $instance->port === null) {
            $instance->port = (int) $serverPort;
        }

        // Path
        $requestUri = $server['REQUEST_URI'] ?? '/';
        $pathPart = parse_url($requestUri, PHP_URL_PATH);
        $instance->path = $pathPart !== false ? $pathPart : '/';

        // Query
        $queryPart = parse_url($requestUri, PHP_URL_QUERY);
        $instance->query = $queryPart !== false && $queryPart !== null ? $queryPart : ($server['QUERY_STRING'] ?? '');

        // Strip standard port
        $instance->port = $instance->filterPort($instance->port);

        return $instance;
    }

    public function getScheme(): string
    {
        return $this->scheme;
    }

    public function getAuthority(): string
    {
        if ($this->host === '') {
            return '';
        }

        $authority = $this->host;

        if ($this->userInfo !== '') {
            $authority = $this->userInfo . '@' . $authority;
        }

        if ($this->port !== null) {
            $authority .= ':' . $this->port;
        }

        return $authority;
    }

    public function getUserInfo(): string
    {
        return $this->userInfo;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): ?int
    {
        return $this->port;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getQuery(): string
    {
        return $this->query;
    }

    public function getFragment(): string
    {
        return $this->fragment;
    }

    public function withScheme(string $scheme): static
    {
        $new = clone $this;
        $new->scheme = strtolower($scheme);
        $new->port = $new->filterPort($new->port);
        return $new;
    }

    public function withUserInfo(string $user, ?string $password = null): static
    {
        $new = clone $this;
        $new->userInfo = $user;
        if ($password !== null && $password !== '') {
            $new->userInfo .= ':' . $password;
        }
        return $new;
    }

    public function withHost(string $host): static
    {
        $new = clone $this;
        $new->host = strtolower($host);
        return $new;
    }

    public function withPort(?int $port): static
    {
        $new = clone $this;
        $new->port = $new->filterPort($port);
        return $new;
    }

    public function withPath(string $path): static
    {
        $new = clone $this;
        $new->path = $path;
        return $new;
    }

    public function withQuery(string $query): static
    {
        $new = clone $this;
        $new->query = ltrim($query, '?');
        return $new;
    }

    public function withFragment(string $fragment): static
    {
        $new = clone $this;
        $new->fragment = $fragment;
        return $new;
    }

    public function __toString(): string
    {
        $uri = '';

        if ($this->scheme !== '') {
            $uri .= $this->scheme . ':';
        }

        $authority = $this->getAuthority();
        if ($authority !== '') {
            $uri .= '//' . $authority;
        }

        $path = $this->path;
        if ($authority !== '' && $path !== '' && $path[0] !== '/') {
            $path = '/' . $path;
        }
        $uri .= $path;

        if ($this->query !== '') {
            $uri .= '?' . $this->query;
        }

        if ($this->fragment !== '') {
            $uri .= '#' . $this->fragment;
        }

        return $uri;
    }

    /**
     * Strip standard ports (80 for http, 443 for https).
     *
     * @param int|null $port The port to filter
     * @return int|null The filtered port, or null if it's a standard port
     */
    private function filterPort(?int $port): ?int
    {
        if ($port === null) {
            return null;
        }

        if ($this->scheme !== '' && isset(self::STANDARD_PORTS[$this->scheme]) && self::STANDARD_PORTS[$this->scheme] === $port) {
            return null;
        }

        return $port;
    }
}