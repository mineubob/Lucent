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
 * Factory: ServerRequest::fromGlobals() replaces the old Request::__construct().
 *
 * @final This class should not be extended in production code.
 *        Use PSR-7 attributes for extension instead. For testing, a
 *        FakeServerRequest subclass is available in Lucent\Faker.
 */
class ServerRequest extends AbstractMessage implements ServerRequestInterface
{
    /** @var string HTTP method */
    private string $method = 'GET';

    /** @var UriInterface|null */
    private ?UriInterface $uri = null;

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

    public function __construct(
        string $method = 'GET',
        ?UriInterface $uri = null,
        array $serverParams = [],
    ) {
        parent::__construct();
        $this->method = strtoupper($method);
        $this->uri = $uri ?? Uri::fromString('/');
        $this->serverParams = $serverParams;
    }

    // ─── Static Factory ─────────────────────────────────────────────────

    /**
     * Create a ServerRequest from PHP superglobals.
     *
     * Replaces the logic in old Lucent\Http\Request::__construct().
     *
     * @param array|null $server $_SERVER (defaults to $_SERVER)
     * @param array|null $query $_GET (defaults to $_GET)
     * @param array|null $body $_POST (defaults to $_POST)
     * @param array|null $cookies $_COOKIE (defaults to $_COOKIE)
     * @param array|null $files $_FILES (defaults to $_FILES, converted to UploadedFileInterface[])
     */
    public static function fromGlobals(
        ?array $server = null,
        ?array $query = null,
        ?array $body = null,
        ?array $cookies = null,
        ?array $files = null,
    ): self {
        $server = $server ?? $_SERVER;
        $method = strtoupper($server['REQUEST_METHOD'] ?? 'GET');
        $uri = Uri::fromGlobals($server);

        $request = new self($method, $uri, $server);

        // Query params
        $request->queryParams = $query ?? $_GET;

        // Cookie params
        $request->cookieParams = $cookies ?? $_COOKIE;

        // Uploaded files — convert $_FILES to UploadedFileInterface[]
        $request->uploadedFiles = self::normalizeUploadedFiles($files ?? $_FILES);

        // Headers
        $request->setHeaders(self::extractHeaders($server));

        // Body — read php://input once
        $rawBody = file_get_contents('php://input');
        $request->setBody(Stream::fromString($rawBody !== false ? $rawBody : ''));

        $contentType = $server['CONTENT_TYPE'] ?? '';
        if (str_contains($contentType, 'application/json') && $rawBody !== false && $rawBody !== '') {
            $decoded = json_decode($rawBody, true);
            if (is_array($decoded)) {
                $request->parsedBody = $decoded;
            }
        } elseif ($body !== null) {
            $request->parsedBody = $body;
        } else {
            $request->parsedBody = $_POST;
        }

        // Protocol version
        if (isset($server['SERVER_PROTOCOL'])) {
            $version = $server['SERVER_PROTOCOL'];
            if (preg_match('#^HTTP/(\d+\.\d+)$#', $version, $matches)) {
                $request->withProtocolVersionInternal($matches[1]);
            }
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
        if ($this->uri === null) {
            $this->uri = Uri::fromString('/');
        }
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
     * @return static
     */
    public function withUploadedFiles(array $uploadedFiles): ServerRequestInterface
    {
        foreach ($uploadedFiles as $file) {
            if (!$file instanceof UploadedFileInterface) {
                throw new \InvalidArgumentException('Uploaded files must be an array of UploadedFileInterface instances');
            }
        }
        $new = clone $this;
        $new->uploadedFiles = $uploadedFiles;
        return $new;
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
     * Context is stored as a PSR-7 attribute (array) and can be set
     * by validation rules (via setContext()) or middleware.
     *
     * @param string $key The context key
     * @param mixed $default Default value if key not found
     * @return mixed
     */
    public function getContext(string $key, mixed $default = null): mixed
    {
        $context = $this->getAttribute('context', []);
        return $context[$key] ?? $default;
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
