[Home](../README.md)

# HTTP Client (PSR-18)

Lucent ships a **PSR-18** (`psr/http-client`) compliant HTTP client backed by cURL. It reuses Lucent's PSR-7/17 message implementations, so requests and responses are standard `Psr\Http\Message\RequestInterface` / `ResponseInterface` objects.

The transport is pluggable: by default requests go through a cURL-backed handler, and the `stream => true` per-request option routes to a PHP stream-wrapper handler for true incremental response streaming (see [Streaming responses](#streaming-responses)).

---

## Quick Start

### Using the `Http` Facade

```php
use Lucent\Facades\Http;

$response = Http::get('https://api.example.com/users');
$status = $response->getStatusCode();          // 200
$body = json_decode((string) $response->getBody(), true);
```

### Using the Client Directly

```php
use Lucent\Http\Client\Psr18Client;

$client = new Psr18Client([
    'base_uri' => 'https://api.example.com/v1',
    'timeout'  => 30,
]);

$response = $client->get('/users', ['page' => 2]);
```

### Sending a PSR-7 Request

```php
use Lucent\Http\Message\Factory\HttpFactory;

$request = (new HttpFactory())->createRequest('GET', 'https://api.example.com/users');
$response = $client->sendRequest($request);
```

---

## Configuration

`Psr18Client` accepts an immutable config array. Unknown keys and invalid values throw `\InvalidArgumentException`.

| Key | Type | Default | Description |
|---|---|---|---|
| `base_uri` | `string\|UriInterface` | `null` | Base URI prepended to relative request URIs (RFC 3986 resolution) |
| `timeout` | `int` | `30` | Request timeout in seconds (must be > 0) |
| `verify_ssl` | `bool` | `true` | Verify SSL certificates |
| `basic_auth` | `array{0: string, 1: string}` | `null` | `[username, password]` for HTTP Basic auth |
| `user_agent` | `string` | `Lucent-Psr18Client/1.0` | User-Agent header |
| `headers` | `array<string, string>` | `[]` | Default headers merged into every request (request headers win) |
| `curl_options` | `array<int, mixed>` | `[]` | Additional cURL options (applied last, can override defaults) |

```php
$client = new Psr18Client([
    'base_uri'    => 'https://api.example.com',
    'timeout'     => 10,
    'verify_ssl'  => true,
    'basic_auth'  => ['username', 'password'],
    'user_agent'  => 'MyApp/1.0',
    'headers'     => ['Accept' => 'application/json'],
    'curl_options'=> [CURLOPT_FOLLOWLOCATION => true],
]);
```

---

## Verb Methods

All verb methods return a PSR-7 `ResponseInterface`.

```php
$client->get($uri, $params, $options);      // GET with query params
$client->post($uri, $body, $options);       // POST
$client->put($uri, $body, $options);        // PUT
$client->patch($uri, $body, $options);      // PATCH
$client->delete($uri, $body, $options);     // DELETE
$client->head($uri, $params, $options);     // HEAD
```

- **`get` / `head`**: `$params` is appended as a query string.
- **`post` / `put` / `patch` / `delete`**: `$body` may be an array (JSON-encoded with `Content-Type: application/json`), a raw string, or a `StreamInterface`.
- **`$options`**: Per-request options array — see [Per-Request Options](#per-request-options) for supported keys (`sink`, `timeout`, `verify_ssl`, `headers`, `curl`, `user_agent`, `basic_auth`, `stream`, `progress`).

```php
// JSON body
$response = Http::post('https://api.example.com/users', ['name' => 'Jane']);

// Raw string body
$response = Http::post('https://api.example.com/users', 'name=Jane&role=admin');
```

---

## Per-Request Options

Per-request options are passed as an array to the verb methods. Supported keys: `sink`, `timeout`, `verify_ssl`, `headers`, `curl`, `user_agent`, `basic_auth`, `stream`, `progress`.

### Sink (write body to disk / stream)

By default the response body is written to a seekable `php://temp` stream. For large downloads, use a **sink** to stream the body to a file path, resource, or `StreamInterface`:

```php
// File path
$response = Http::get('https://example.com/big-file.zip', [], ['sink' => '/tmp/big-file.zip']);

// Caller-owned resource
$resource = fopen('/tmp/big-file.zip', 'w+');
$response = Http::get('https://example.com/big-file.zip', [], ['sink' => $resource]);

// PSR-7 stream
$stream = \Lucent\Http\Message\Stream::fromResource(fopen('/tmp/big-file.zip', 'w+'));
$response = Http::get('https://example.com/big-file.zip', [], ['sink' => $stream]);
```

When a sink is a string path, the client opens and owns the file. When it is a resource or stream, the caller owns it (the client does not close it).

### Timeout & SSL per request

```php
$response = Http::get('https://example.com/slow', [], [
    'timeout'    => 5,
    'verify_ssl' => false,
]);
```

### Per-request headers, user agent, basic auth

```php
$response = Http::post('https://api.example.com/users', ['name' => 'Jane'], [
    'headers'    => ['Accept' => 'application/json'],
    'user_agent' => 'MyApp/2.0',
    'basic_auth' => ['user', 'pass'],
]);
```

### Raw cURL options

Pass through extra cURL options with `curl` (options the client manages — `CURLOPT_URL`, `CURLOPT_WRITEFUNCTION`, `CURLOPT_RETURNTRANSFER`, `CURLOPT_HEADERFUNCTION`, `CURLOPT_POSTFIELDS`, `CURLOPT_HTTPHEADER`, `CURLOPT_USERPWD`, `CURLOPT_USERAGENT`, `CURLOPT_TIMEOUT`, SSL options, and progress options `CURLOPT_NOPROGRESS` / `CURLOPT_XFERINFOFUNCTION` / `CURLOPT_PROGRESSFUNCTION` — are rejected):

```php
$response = Http::get('https://example.com/', [], [
    'curl' => [CURLOPT_FRESH_CONNECT => true],
]);
```

### Progress callback

Track transfer progress with the `progress` option. The callback receives `($downloadedBytes, $totalBytes, $uploadedBytes, $uploadTotalBytes)` — download-first, with upload values appended for callers that need them. PHP ignores extra arguments, so a 2-arg callback works fine for download-only progress:

```php
// Download-only progress (2 args)
$response = Http::get('https://example.com/big-file.zip', [], [
    'sink'     => '/tmp/big-file.zip',
    'progress' => function (int $downloaded, int $total) {
        echo "{$downloaded} / {$total} bytes\n";
    },
]);

// Full transfer details (4 args)
$response = Http::post('https://example.com/upload', $data, [
    'progress' => function (int $dl, int $dlTotal, int $ul, int $ulTotal) {
        echo "downloaded: {$dl}/{$dlTotal}, uploaded: {$ul}/{$ulTotal}\n";
    },
]);
```

### Streaming responses

By default the client buffers the entire response body before returning. For large or long-lived responses (e.g. server-sent events, chunked downloads) you can opt into **true incremental streaming** with `stream => true`:

```php
$response = Http::get('https://example.com/events', [], ['stream' => true]);

$body = $response->getBody();
while (!$body->eof()) {
    $line = $body->read(1024);
    // process each chunk as it arrives
}
```

With `stream => true` the response body is the **live transport stream** — data is read as it arrives, so memory stays flat regardless of response size. This comes with trade-offs:

- The body is **one-shot and non-seekable** — you can only read it forward, once. You cannot rewind, `(string) $body`, or `json_decode((string) $body)` after reading.
- The response is **not reusable** — the underlying socket is consumed by reading the body.
- The `curl` and `progress` options are **not supported** on the stream path (the stream transport ignores cURL options and has no progress callback).
- Requires `allow_url_fopen` (the stream transport uses PHP's HTTP stream wrapper rather than cURL).

For buffered responses (the default), the body is a seekable `php://temp` stream and can be read, rewound, and cast to string freely.

---

## Error Handling

PSR-18 defines an exception hierarchy. Lucent throws:

| Exception | When |
|---|---|
| `Lucent\Http\Client\Exception\NetworkException` | Transport failures — DNS resolution, connection refused, timeout, SSL connect |
| `Lucent\Http\Client\Exception\RequestException` | Request-level failures — invalid URL, cURL errors that aren't transport-level |

Both implement `Psr\Http\Client\ClientExceptionInterface` and expose `getRequest()` returning the original request.

```php
use Lucent\Http\Client\Exception\NetworkException;
use Lucent\Http\Client\Exception\RequestException;

try {
    $response = Http::get('https://api.example.com/users');
} catch (NetworkException $e) {
    // Host unreachable, connection refused, timeout...
    $failedRequest = $e->getRequest();
} catch (RequestException $e) {
    // Invalid request...
}
```

## PSR-18 Compliance

- Implements `Psr\Http\Client\ClientInterface` (`sendRequest()`).
- Throws `Psr\Http\Client\ClientExceptionInterface` subclasses (`NetworkException`, `RequestException`).
- Accepts and returns standard PSR-7 messages.
- Declares `psr/http-client-implementation` in `composer.json`.