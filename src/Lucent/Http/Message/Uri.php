<?php

declare(strict_types=1);

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
     * Validation flags for isValid().
     *
     * By default (flags = 0) relative references such as "/users/123",
     * "?page=2" or "#top" are accepted, matching how Uri represents URIs.
     */
    public const VALIDATE_RELATIVE = 0b0001; // accept path-only / relative references
    public const VALIDATE_ABSOLUTE = 0b0010; // require a scheme (e.g. http:, https:)
    public const VALIDATE_HOST     = 0b0100; // require a non-empty host
    public const VALIDATE_STRICT   = 0b1000; // reject non-standard forms

    /**
     * Sensible default for validating a full absolute URL.
     *
     * Resolves to VALIDATE_HOST | VALIDATE_ABSOLUTE — requires a scheme and a
     * non-empty host, but allows any scheme (http, https, ftp, mailto, ...).
     */
    public const VALIDATE_DEFAULT = self::VALIDATE_HOST | self::VALIDATE_ABSOLUTE;

    /**
     * Unreserved characters (RFC 3986 §2.3) plus sub-delims (§2.2) that are
     * always allowed unencoded in path/query/fragment components.
     */
    private const CHAR_UNRESERVED = 'A-Za-z0-9\-._~!$&\'()*+,;=';

    /**
     * Private constructor — use fromString() or fromServer() instead.
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
        if (!self::isValid($uri)) {
            throw new \InvalidArgumentException("Unable to parse URI: $uri");
        }

        $parts = parse_url($uri);

        $instance = new self();
        $instance->scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : '';
        $instance->host = isset($parts['host']) ? strtolower($parts['host']) : '';
        $instance->port = isset($parts['port']) ? $instance->filterPort((int) $parts['port']) : null;
        $instance->path = self::encodePath($parts['path'] ?? '');
        $instance->query = self::encodeQueryOrFragment($parts['query'] ?? '');
        $instance->fragment = self::encodeQueryOrFragment($parts['fragment'] ?? '');
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
    public static function fromServer(array $server): self
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
        $instance->path = self::encodePath($pathPart !== false && $pathPart !== null ? $pathPart : '/');

        // Query
        $queryPart = parse_url($requestUri, PHP_URL_QUERY);
        $instance->query = self::encodeQueryOrFragment($queryPart !== false && $queryPart !== null ? $queryPart : ($server['QUERY_STRING'] ?? ''));

        // Strip standard port
        $instance->port = $instance->filterPort($instance->port);

        return $instance;
    }

    /**
     * Validate a URI string without throwing.
     *
     * By default (flags = 0) relative references such as "/users/123",
     * "?page=2" or "#top" are accepted. Combine the VALIDATE_* flags to
     * narrow the check:
     *
     *   Uri::isValid('https://example.com/path', Uri::VALIDATE_ABSOLUTE | Uri::VALIDATE_HOST)
     *
     * @param string $uri   The URI string to validate
     * @param int    $flags Bitmask of VALIDATE_* constants
     * @return bool Whether the URI is well-formed and satisfies the flags
     */
    public static function isValid(string $uri, int $flags = 0): bool
    {
        // Reject control characters in the raw URI. parse_url() silently
        // converts them to '_', so they must be checked before parsing.
        if (preg_match('/[\x00-\x1F\x7F]/', $uri)) {
            return false;
        }

        $parts = parse_url($uri);
        if ($parts === false) {
            return false;
        }

        // Validate the port range when present.
        if (isset($parts['port']) && ($parts['port'] < 0 || $parts['port'] > 65535)) {
            return false;
        }

        // Validate the host when present.
        if (isset($parts['host']) && !self::isValidHost($parts['host'])) {
            return false;
        }

        // VALIDATE_ABSOLUTE: require a scheme.
        if (($flags & self::VALIDATE_ABSOLUTE) && !isset($parts['scheme'])) {
            return false;
        }

        // VALIDATE_HOST: require a non-empty host.
        if (($flags & self::VALIDATE_HOST) && empty($parts['host'])) {
            return false;
        }

        // VALIDATE_STRICT: reject non-standard forms.
        if ($flags & self::VALIDATE_STRICT) {
            // A scheme must be present and be http/https.
            if (isset($parts['scheme']) && !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
                return false;
            }

            // A host must be present.
            if (empty($parts['host'])) {
                return false;
            }
        }

        return true;
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

    /**
     * @throws \InvalidArgumentException for invalid or unsupported schemes
     */
    public function withScheme(string $scheme): static
    {
        $scheme = strtolower($scheme);
        if ($scheme !== '' && !isset(self::STANDARD_PORTS[$scheme])) {
            throw new \InvalidArgumentException("Unsupported scheme: '$scheme' (only http and https are supported)");
        }

        $new = clone $this;
        $new->scheme = $scheme;
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

    /**
     * @throws \InvalidArgumentException for invalid hostnames
     */
    public function withHost(string $host): static
    {
        if ($host !== '' && !self::isValidHost($host)) {
            throw new \InvalidArgumentException("Invalid host: '$host'");
        }

        $new = clone $this;
        $new->host = strtolower($host);
        return $new;
    }

    /**
     * @throws \InvalidArgumentException for ports outside the TCP/UDP range (0–65535)
     */
    public function withPort(?int $port): static
    {
        if ($port !== null && ($port < 0 || $port > 65535)) {
            throw new \InvalidArgumentException("Invalid port: $port (must be 0-65535)");
        }

        $new = clone $this;
        $new->port = $new->filterPort($port);
        return $new;
    }

    /**
     * @throws \InvalidArgumentException for invalid paths
     */
    public function withPath(string $path): static
    {
        self::assertNoControlChars($path, 'path');

        $new = clone $this;
        $new->path = self::encodePath($path);
        return $new;
    }

    /**
     * @throws \InvalidArgumentException for invalid query strings
     */
    public function withQuery(string $query): static
    {
        self::assertNoControlChars($query, 'query');

        $new = clone $this;
        $new->query = self::encodeQueryOrFragment(ltrim($query, '?'));
        return $new;
    }

    /**
     * @throws \InvalidArgumentException for invalid fragments
     */
    public function withFragment(string $fragment): static
    {
        self::assertNoControlChars($fragment, 'fragment');

        $new = clone $this;
        $new->fragment = self::encodeQueryOrFragment($fragment);
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
            // Rootless path with an authority must be prefixed by "/".
            $path = '/' . $path;
        }
        if ($authority === '' && str_starts_with($path, '//')) {
            // A path starting with more than one "/" and no authority is
            // reduced to a single leading slash. Note: a network-path
            // reference ("//host/path") parses its leading segment into the
            // host, so this only triggers for paths set via withPath().
            $path = '/' . ltrim($path, '/');
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

    /**
     * Percent-encode a path component per RFC 3986 §3.3.
     *
     * Existing percent-encoded triplets are preserved (no double-encoding).
     *
     * @param string $path The raw path
     * @return string The percent-encoded path
     */
    private static function encodePath(string $path): string
    {
        return self::encodeComponent($path, self::CHAR_UNRESERVED . ':@\/');
    }

    /**
     * Percent-encode a query or fragment component per RFC 3986 §3.4/§3.5.
     *
     * Existing percent-encoded triplets are preserved (no double-encoding).
     *
     * @param string $value The raw query or fragment
     * @return string The percent-encoded value
     */
    private static function encodeQueryOrFragment(string $value): string
    {
        return self::encodeComponent($value, self::CHAR_UNRESERVED . ':@\/\?');
    }

    /**
     * Percent-encode any character outside the allowed set, preserving
     * existing valid %XX triplets.
     *
     * @param string $value The raw component value
     * @param string $allowedChars Regex character class content for allowed chars
     * @return string The encoded component
     */
    private static function encodeComponent(string $value, string $allowedChars): string
    {
        return (string) preg_replace_callback(
            '/(?:%[0-9A-Fa-f]{2})|[^' . $allowedChars . ']/',
            static function (array $matches): string {
                $char = $matches[0];
                // Preserve existing valid percent-encoded triplets.
                if (strlen($char) === 3 && $char[0] === '%') {
                    return $char;
                }
                return rawurlencode($char);
            },
            $value
        );
    }

    /**
     * Validate a hostname (DNS name, IPv4, or bracketed IPv6 literal).
     */
    private static function isValidHost(string $host): bool
    {
        // Bracketed IPv6 literal, e.g. [::1]
        if (str_starts_with($host, '[')) {
            return str_ends_with($host, ']')
                && filter_var(substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        }

        // IPv4
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return true;
        }

        // DNS hostname (labels of alphanumerics and hyphens, dot-separated)
        return (bool) preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-]*[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9\-]*[a-zA-Z0-9])?)*\.?$/', $host);
    }

    /**
     * Reject control characters (including null bytes, CR, LF) in a component.
     *
     * @throws \InvalidArgumentException
     */
    private static function assertNoControlChars(string $value, string $component): void
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $value)) {
            throw new \InvalidArgumentException("Invalid URI $component: contains control characters");
        }
    }
}