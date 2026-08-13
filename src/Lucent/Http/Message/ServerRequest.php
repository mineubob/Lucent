<?php

namespace Lucent\Http\Message;

use Lucent\Http\Message\UploadedFile;
use Lucent\Http\RouteInfo;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\UriInterface;

/**
 * ServerRequest implementation.
 *
 * Lucent-specific data (routeInfo, urlVars, context) is stored as
 * attributes, which is the PSR-7-sanctioned extension mechanism.
 *
 * Factory: ServerRequest::capture() reads from PHP superglobals (production).
 * ServerRequest::create() builds from explicit values (testing/fabrication).
 *
 * @final This class should not be extended. Use ServerRequest::create()
 *        for testing/fabrication and ServerRequest::capture() for production.
 */
class ServerRequest extends AbstractMessage implements ServerRequestInterface
{
    /** @var string HTTP method */
    private string $method = 'GET';

    /** @var UriInterface */
    private UriInterface $uri;

    /** @var array Server parameters ($_SERVER) */
    private array $serverParams = [];

    /** @var array Cookie parameters ($_COOKIE) */
    protected array $cookieParams = [];

    /** @var array Query string parameters ($_GET) */
    protected array $queryParams = [];

    /** @var array Uploaded files ($_FILES) */
    private array $uploadedFiles = [];

    /** @var array|null Parsed body ($_POST or parsed JSON) */
    protected array|null $parsedBody = null;

    /** @var array Attributes (PSR-7 extension mechanism — stores routeInfo, urlVars, context) */
    private array $attributes = [];

    /** @var string|null Request target */
    private ?string $requestTarget = null;

    private function __construct(
        string $method = 'GET',
        ?UriInterface $uri = null,
        array $serverParams = [],
    ) {
        parent::__construct();
        $this->method = strtoupper($method);
        $this->uri = $uri ?? Uri::fromString('/');
        $this->serverParams = $serverParams;

        // A request built with a URI should carry that URI's host as its
        // Host header unless one was already provided.
        $host = $this->uri->getHost();
        if ($host !== '') {
            $port = $this->uri->getPort();
            $this->withHeaderInternal('Host', $port !== null ? $host . ':' . $port : $host);
        }
    }

    // ─── Static Factory ─────────────────────────────────────────────────

    /**
     * Capture the incoming HTTP request from PHP superglobals.
     *
     * This is the production entry point — reads from $_SERVER, $_GET,
     * $_POST, $_COOKIE, $_FILES, and php://input. Takes no arguments.
     *
     * For tests, use {@see create()} to build a request from explicit
     * values instead of relying on global state.
     *
     * @return self
     */
    public static function capture(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = Uri::fromServer($_SERVER);

        $request = new self($method, $uri, $_SERVER);

        $request->queryParams = $_GET;
        $request->cookieParams = $_COOKIE;
        $request->uploadedFiles = self::normalizeUploadedFiles($_FILES);
        $request->setHeaders(self::extractHeaders($_SERVER));

        // One mutable context bag per request, shared by every copy of the
        // request (see getContext()/withContext()).
        $request->attributes['context'] = new RequestContext();

        // Body — read php://input once (only meaningful in a real request)
        $rawBody = file_get_contents('php://input');
        $request->setBody(Stream::fromString($rawBody !== false ? $rawBody : ''));

        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($contentType, 'application/json') && $rawBody !== false && $rawBody !== '') {
            $decoded = json_decode($rawBody, true);
            if (is_array($decoded)) {
                $request->parsedBody = $decoded;
            }
        } else {
            $request->parsedBody = $_POST;
        }

        if (isset($_SERVER['SERVER_PROTOCOL'])) {
            $version = $_SERVER['SERVER_PROTOCOL'];
            if (preg_match('#^HTTP/(\d+\.\d+)$#', $version, $matches)) {
                $request->withProtocolVersionInternal($matches[1]);
            }
        }

        return $request;
    }

    /**
     * Create a ServerRequest from explicit values.
     *
     * Builds a request from a method, URI, and
     * optional parameters without touching global
     * state.
     *
     * The URI should be the path only (e.g. '/users/42'). If a query string
     * is included in the URI (e.g. '/search?q=test'), it is parsed and
     * merged with the $query parameter.
     *
     * @param string $method       HTTP method (GET, POST, etc.)
     * @param string|UriInterface $uri  URI path string or object (defaults to '/')
     * @param array $query         Query string parameters
     * @param array $body          Parsed body parameters
     * @param array $cookies       Cookie parameters
     * @param array $files         Uploaded files as $_FILES-style array
     * @param array $headers       Headers as [name => value, ...] or [name => [value, ...]]
     * @param array $server        Server parameters ($_SERVER-style)
     * @return self
     */
    public static function create(
        string $method = 'GET',
        string|UriInterface $uri = '/',
        array $query = [],
        array $body = [],
        array $cookies = [],
        array $files = [],
        array $headers = [],
        array $server = [],
    ): self {
        $method = strtoupper($method);
        $uriObject = $uri instanceof UriInterface ? $uri : Uri::fromString($uri);

        // If the URI has a query string, parse it and merge with $query
        // (explicit $query params take precedence)
        $uriQuery = $uriObject->getQuery();
        if ($uriQuery !== '') {
            parse_str($uriQuery, $parsedQuery);
            $query = array_merge($parsedQuery, $query);
            // Strip the query from the URI so getRequestTarget() uses
            // queryParams consistently
            $uriObject = $uriObject->withQuery('');
        }

        // Build minimal $_SERVER-style array if not provided
        $server = array_merge([
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => $uriObject->getPath() ?: '/',
            'SERVER_PROTOCOL' => 'HTTP/1.1',
            'HTTP_HOST' => $uriObject->getHost() ?: 'localhost',
        ], $server);

        $request = new self($method, $uriObject, $server);

        $request->queryParams = $query;
        $request->cookieParams = $cookies;
        $request->uploadedFiles = self::normalizeUploadedFiles($files);

        // One mutable context bag per request, shared by every copy of the
        // request (see getContext()/withContext()).
        $request->attributes['context'] = new RequestContext();

        // Apply explicit headers (overrides anything extracted from $server)
        $request->setHeaders(self::extractHeaders($server));
        foreach ($headers as $name => $value) {
            $request->withHeaderInternal($name, is_array($value) ? $value : [$value]);
        }

        // Body — no php://input in tests, use $body directly
        // null when no body provided (matches PSR-7 convention for "no body")
        $request->parsedBody = $body !== [] ? $body : null;

        // Set Content-Type if body is present and no Content-Type was given
        if ($body !== [] && !isset($headers['Content-Type'])) {
            $request->withHeaderInternal('Content-Type', ['application/x-www-form-urlencoded']);
        }

        return $request;
    }

    // ─── RequestInterface ───────────────────────────────────────────────

    public function getRequestTarget(): string
    {
        if ($this->requestTarget !== null) {
            return $this->requestTarget;
        }

        $target = $this->uri?->getPath() ?? '/';
        $query = $this->uri?->getQuery() ?? '';
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
        if ($method === '' || !preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/', $method)) {
            throw new \InvalidArgumentException("Invalid HTTP method: '$method'");
        }

        $new = clone $this;
        $new->method = $method;
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

    // ─── ServerRequestInterface ─────────────────────────────────────────

    public function getServerParams(): array
    {
        return $this->serverParams;
    }

    public function getCookieParams(): array
    {
        return $this->cookieParams;
    }

    /**
     * @return static
     */
    public function withCookieParams(array $cookies): ServerRequestInterface
    {
        $new = clone $this;
        $new->cookieParams = $cookies;
        return $new;
    }

    public function getQueryParams(): array
    {
        return $this->queryParams;
    }

    /**
     * @return static
     */
    public function withQueryParams(array $query): ServerRequestInterface
    {
        $new = clone $this;
        $new->queryParams = $query;
        return $new;
    }

    public function getUploadedFiles(): array
    {
        return $this->uploadedFiles;
    }

    /**
     * @param array $uploadedFiles An array tree of UploadedFileInterface instances
     * @throws \InvalidArgumentException if an invalid structure is provided
     * @return static
     */
    public function withUploadedFiles(array $uploadedFiles): ServerRequestInterface
    {
        self::assertUploadedFilesTree($uploadedFiles);

        $new = clone $this;
        $new->uploadedFiles = $uploadedFiles;
        return $new;
    }

    /**
     * Recursively validate an uploaded-files tree (nested arrays of
     * UploadedFileInterface instances are allowed).
     *
     * @throws \InvalidArgumentException
     */
    private static function assertUploadedFilesTree(array $tree): void
    {
        foreach ($tree as $file) {
            if (is_array($file)) {
                self::assertUploadedFilesTree($file);
                continue;
            }
            if (!$file instanceof UploadedFileInterface) {
                throw new \InvalidArgumentException('Uploaded files must be an array tree of UploadedFileInterface instances');
            }
        }
    }

    public function getParsedBody(): array|object|null
    {
        return $this->parsedBody;
    }

    /**
     * @return static
     */
    public function withParsedBody($data): ServerRequestInterface
    {
        if ($data !== null && !is_array($data) && !is_object($data)) {
            throw new \InvalidArgumentException('Parsed body must be null, an array, or an object');
        }
        $new = clone $this;
        $new->parsedBody = $data;
        return $new;
    }

    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * Get a single PSR-7 attribute by name.
     *
     * @param string $name The attribute name
     * @param mixed $default Default value if the attribute is not set
     * @return mixed The attribute value, or $default on a miss
     */
    public function getAttribute(string $name, $default = null): mixed
    {
        return array_key_exists($name, $this->attributes) ? $this->attributes[$name] : $default;
    }

    /**
     * @return static
     */
    public function withAttribute(string $name, $value): ServerRequestInterface
    {
        $new = clone $this;
        $new->attributes[$name] = $value;
        return $new;
    }

    /**
     * @return static
     */
    public function withoutAttribute(string $name): ServerRequestInterface
    {
        $new = clone $this;
        unset($new->attributes[$name]);
        return $new;
    }

    // ─── Lucent-Specific Getters (convenience wrappers around attributes) ─

    /**
     * Get the RouteInfo stored as a PSR-7 attribute.
     *
     * @return RouteInfo|null
     */
    public function getRouteInfo(): ?RouteInfo
    {
        return $this->getAttribute('routeInfo');
    }

    /**
     * Get the URL variables stored as a PSR-7 attribute.
     *
     * @return array<string, string>
     */
    public function getUrlVars(): array
    {
        return $this->getAttribute('urlVars', []);
    }

    /**
     * Get a single URL variable by name.
     *
     * @param string $name The variable name
     * @param mixed $default Default value if not found
     * @return mixed
     */
    public function getUrlVar(string $name, mixed $default = null): mixed
    {
        return $this->getUrlVars()[$name] ?? $default;
    }

    /**
     * Get a value from the request context.
     *
     * Context lives in a mutable {@see RequestContext} bag attached to the
     * request, so it can be written by validation rules (via withContext())
     * or middleware and read back anywhere that holds the same request.
     *
     * @param string $key The context key
     * @param mixed $default Default value if key not found
     * @return mixed
     */
    public function getContext(string $key, mixed $default = null): mixed
    {
        return RequestContext::fromRequest($this)?->get($key) ?? $default;
    }

    /**
     * Set a value in the request context.
     *
     * Mutates the shared {@see RequestContext} bag in place, so the write is
     * visible to every copy of the request (no clone needed). Returns $this
     * for chaining.
     *
     * @param string $key The context key
     * @param mixed $value The value to store
     * @return static
     */
    public function withContext(string $key, mixed $value): static
    {
        RequestContext::fromRequest($this)?->set($key, $value);
        return $this;
    }

    // ─── Internal Helpers ───────────────────────────────────────────────

    /**
     * Set protocol version internally (no clone).
     *
     * @param string $version The HTTP protocol version (e.g., "1.1")
     * @return void
     */
    private function withProtocolVersionInternal(string $version): void
    {
        $this->protocolVersion = $version;
    }

    /**
     * Extract headers from $_SERVER.
     *
     * @param array $server The $_SERVER array
     * @return array<string, string[]> Headers as [name => [value, ...]]
     */
    private static function extractHeaders(array $server): array
    {
        $headers = [];

        foreach ($server as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace('_', '-', substr($key, 5));
                $name = ucwords(strtolower($name), '-');
                if (is_string($value)) {
                    $headers[$name] = [$value];
                }
            }
        }

        // Content-Type is not prefixed with HTTP_
        if (isset($server['CONTENT_TYPE'])) {
            $headers['Content-Type'] = [is_string($server['CONTENT_TYPE']) ? $server['CONTENT_TYPE'] : ''];
        }

        // Content-Length is not prefixed with HTTP_
        if (isset($server['CONTENT_LENGTH'])) {
            $headers['Content-Length'] = [(string) $server['CONTENT_LENGTH']];
        }

        return $headers;
    }

    /**
     * Normalize $_FILES into an array of UploadedFileInterface instances.
     *
     * Handles both simple and nested (array of inputs) $_FILES formats.
     *
     * @param array $files The $_FILES array to normalize
     * @return array<string, UploadedFileInterface|UploadedFileInterface[]> Normalized file tree
     */
    private static function normalizeUploadedFiles(array $files): array
    {
        $normalized = [];

        foreach ($files as $key => $value) {
            if (is_array($value) && isset($value['tmp_name'])) {
                // Single file upload
                $normalized[$key] = self::createUploadedFile($value);
            } elseif (is_array($value)) {
                // Nested array of files (e.g., name[] inputs)
                $normalized[$key] = self::normalizeUploadedFiles($value);
            }
            // Malformed entries (non-array values, or arrays without a
            // 'tmp_name' key that are not nested trees) are skipped — they
            // cannot be mapped to an UploadedFileInterface.
        }

        return $normalized;
    }

    /**
     * Create an UploadedFile instance from a $_FILES entry.
     *
     * @param array $file A single $_FILES entry with keys: tmp_name, size, error, name, type
     * @return UploadedFileInterface|UploadedFileInterface[]
     */
    private static function createUploadedFile(array $file): UploadedFileInterface|array
    {
        $tmpName = $file['tmp_name'] ?? '';
        $size = isset($file['size']) ? (int) $file['size'] : null;
        $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        $clientName = $file['name'] ?? null;
        $clientType = $file['type'] ?? null;

        if (is_array($tmpName)) {
            // Multi-file input (name[])
            $files = [];
            foreach ($tmpName as $i => $tmp) {
                $files[] = self::createUploadedFile([
                    'tmp_name' => $tmp,
                    'size' => is_array($size) ? ($size[$i] ?? null) : null,
                    'error' => is_array($error) ? ($error[$i] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE,
                    'name' => is_array($clientName) ? ($clientName[$i] ?? null) : null,
                    'type' => is_array($clientType) ? ($clientType[$i] ?? null) : null,
                ]);
            }
            return $files;
        }

        return new UploadedFile($tmpName, $size, $error, $clientName, $clientType);
    }
}
