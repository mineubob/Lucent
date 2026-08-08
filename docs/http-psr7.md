# PSR-7 HTTP Messages Migration Guide

Lucent now implements **PSR-7** (`psr/http-message`), **PSR-15** (`psr/http-server-handler`, `psr/http-server-middleware`), and **PSR-17** (`psr/http-factory`) standards. This guide explains the new API and how to migrate from the legacy HTTP classes.

---

## Quick Start

### Returning a PSR-7 Response from a Controller

```php
use Lucent\Http\Message\Response;

class UserController
{
    public function index(): Response
    {
        return Response::json(['users' => $users], 200);
    }
}
```

### Using the Convenience Factory

```php
use Lucent\Http\Message\Factory\LucentResponseFactory;

$factory = new LucentResponseFactory();

$response = $factory->createJsonResponse($data, 201);
$response = $factory->createRedirectResponse('/login', 302);
$response = $factory->createEventStreamResponse($generator);
```

### Using PSR-15 Middleware

```php
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ResponseInterface;

class AuthMiddleware implements MiddlewareInterface
{
    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        if (!$request->hasHeader('Authorization')) {
            return (new \Lucent\Http\Message\Response())->withStatus(401);
        }
        return $handler->handle($request);
    }
}
```

---

## New Classes

| PSR Standard | New Class | Replaces |
|---|---|---|
| PSR-7 Message | `Lucent\Http\Message\Response` | `Lucent\Http\HttpResponse`, `JsonResponse`, `RedirectResponse` |
| PSR-7 Message | `Lucent\Http\Message\ServerRequest` | `Lucent\Http\Request` |
| PSR-7 Message | `Lucent\Http\Message\Request` | (client-side requests) |
| PSR-7 Message | `Lucent\Http\Message\Stream` | — |
| PSR-7 Message | `Lucent\Http\Message\Uri` | — |
| PSR-7 Message | `Lucent\Http\Message\UploadedFile` | — |
| PSR-7 Stream | `Lucent\Http\Message\Stream\CallbackStream` | `EventStreamResponse` callback mechanism |
| PSR-7 Stream | `Lucent\Http\Message\Stream\IteratorStream` | `StreamController::stream()` generator |
| PSR-15 Middleware | `Lucent\Http\Middleware\MiddlewarePipeline` | — |
| PSR-17 Factory | `Lucent\Http\Message\Factory\HttpFactory` | — |
| Convenience | `Lucent\Http\Message\Factory\LucentResponseFactory` | — |
| PSR-18 Client | `Lucent\Http\Client\HttpClient` | (new — see [HTTP Client guide](./http-client.md)) |
| PSR-18 Exception | `Lucent\Http\Client\Exception\NetworkException` | — |
| PSR-18 Exception | `Lucent\Http\Client\Exception\RequestException` | — |
| URI Resolution | `Lucent\Http\Message\UriResolver` | — |

---

## PSR-18 HTTP Client

Lucent also implements **PSR-18** (`psr/http-client`) with a cURL-backed client that reuses the PSR-7/17 messages above. See the [HTTP Client guide](./http-client.md) for usage and configuration.

```php
use Lucent\Facades\Http;

$response = Http::get('https://api.example.com/users');
```

---

## Migration Guide

### 1. Controllers: Return `Response` instead of `HttpResponse`/`JsonResponse`

**Before:**
```php
public function index(): JsonResponse
{
    return (new JsonResponse($data))
        ->setMessage('Users retrieved')
        ->setOutcome(true)
        ->setStatusCode(200);
}
```

**After:**
```php
use Lucent\Http\Message\Response;

public function index(): Response
{
    return Response::json($data, 200);
}
```

Or with the envelope format:
```php
return (new Response())->withJsonEnvelope($data, 'Users retrieved', true, 200);
```

### 2. Redirects

**Before:**
```php
return new RedirectResponse('/new-url', 301);
```

**After:**
```php
return (new Response())->withRedirect('/new-url', 301);
```

### 3. Server-Sent Events (SSE) / Streaming

**Before:**
```php
class EventController extends StreamController
{
    protected function stream(): Generator
    {
        while (true) {
            yield Event::data('update', ['time' => time()]);
            sleep(1);
        }
    }
}
```

**After:**
```php
use Lucent\Http\Message\Response;

public function stream(): Response
{
    return (new Response())->withEventStream(function () {
        while (true) {
            echo "data: " . json_encode(['time' => time()]) . "\n\n";
            flush();
            sleep(1);
        }
    });
}
```

Or with a generator:
```php
return (new Response())->withEventStream($this->generateEvents());
```

### 4. Accessing Request Data

**Before:**
```php
public function store(Request $request): JsonResponse
{
    $name = $request->input('name');
    $email = $request->header('X-Email');
}
```

**After — using Lucent's own ServerRequest (no PSR-7 import needed):**
```php
use Lucent\Http\Message\Response;
use Lucent\Http\Message\ServerRequest;

public function store(ServerRequest $request): Response
{
    $body = $request->getParsedBody();
    $name = $body['name'] ?? '';
    $email = $request->getHeaderLine('X-Email');
}
```

**Or use the Slim convention (alias PSR-7 interfaces):**
```php
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
```

### 5. Route Info and URL Variables

Lucent-specific data is stored as PSR-7 attributes, with convenience getters on `ServerRequest`:

```php
$routeInfo = $request->getRouteInfo();  // RouteInfo object
$urlVars   = $request->getUrlVars();    // array
```

### 6. Validation

Rules can be used with PSR-7 requests via the static `Rule::validateRequest()` helper.
The request is passed by reference, so `setContext()` updates propagate back
automatically:

```php
use Lucent\Http\Message\ServerRequest;

$errors = Rule::validateRequest($request, MyRule::class);
```

With explicit data (overrides parsed body):
```php
$errors = Rule::validateRequest($request, MyRule::class, $customData);
```

For plain data without a request:
```php
$errors = Rule::validateData($input, MyRule::class);
```

In a custom rule that stores context:
```php
class MyRule extends Rule
{
    private function custom_rule(string $table, string $column, string $value): bool
    {
        $model = Model::where($column, $value)->getFirst();
        if ($model !== null) {
            $this->setContext($table, $model); // auto-updates caller's $request
        }
        return $model === null;
    }
}
```

### 7. Middleware

**Before (old Lucent Middleware):**
```php
use Lucent\Middleware;

class AuthMiddleware extends Middleware
{
    public function handle(): void
    {
        // ...
    }
}
```

**After (PSR-15):**

Middleware requires PSR-15 interfaces directly (unavoidable — the `process()` signature is dictated by `Psr\Http\Server\MiddlewareInterface`):

```php
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ResponseInterface;
use Lucent\Http\Message\ServerRequest;

class AuthMiddleware implements MiddlewareInterface
{
    public function process(
        ServerRequest $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        // ... authentication logic ...
        return $handler->handle($request);
    }
}
```

**Or use the Slim convention (alias to short names):**
```php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface as Middleware;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Lucent\Http\Message\ServerRequest;

class AuthMiddleware implements Middleware
{
    public function process(Request $request, RequestHandler $handler): Response
    {
        // ...
    }
}
```

---

## Global Middleware

Global middleware runs on **every** request, including routing failures and
dispatch errors. Register it via the `App` facade:

```php
use Lucent\Facades\App;

App::registerGlobalMiddlewares(AuthMiddleware::class);
App::registerGlobalMiddlewares(new CorsMiddleware());
```

You may pass either a middleware instance or a class-string. Global middleware
is resolved up front and wraps the entire request lifecycle, so it sees every
response the application produces — a matched route, a 404 (no route matched),
a 403 (disabled route), or a 500 (dispatch error) — and may short-circuit any
of them by returning a response without calling `$handler->handle()`.

### Execution order

Global middleware runs **before** route-scoped middleware:

```
global middleware → route middleware → controller
```

For a matched route, the response unwinds back through route middleware and
then global middleware, so global middleware wraps the final response.

### Request attributes

`routeInfo` and `urlVars` are attached to the request **after** routing, so
they are visible to route-scoped middleware and controllers, but **not** to
global middleware (which runs before routing). On a routing failure (404/403)
these attributes are absent entirely — `$request->getAttribute('routeInfo')`
returns `null`. Middleware that needs route context should be registered as
route-scoped middleware instead.

### Error handling

Exceptions thrown by global middleware itself are caught and converted to a
500 response, so they never escape the request handler.

---

## Streaming Responses

PSR-7 streaming is handled through `StreamInterface` implementations:

### CallbackStream
Wraps a callable that is invoked once when the stream is read:

```php
use Lucent\Http\Message\Stream\CallbackStream;

$response = (new Response())
    ->withBody(new CallbackStream(function () {
        // Long computation or external API call
        return $result;
    }));
```

### IteratorStream
Wraps a generator/`Traversable` for incremental output:

```php
use Lucent\Http\Message\Stream\IteratorStream;

$response = (new Response())
    ->withBody(new IteratorStream($this->generateLargeDataset()));
```

The streaming-aware emitter in `Application::executeHttpRequest()` reads the body in a loop with `flush()`, so both work for SSE, large file downloads, and incremental data generation.

---

## PSR-17 Factories

### HttpFactory (PSR-17 standard)
Creates bare HTTP messages for dependency injection / interop:

```php
use Lucent\Http\Message\Factory\HttpFactory;

$factory = new HttpFactory();
$request  = $factory->createRequest('GET', 'https://api.example.com');
$response = $factory->createResponse(200);
$stream   = $factory->createStream('content');
$uri      = $factory->createUri('https://example.com');
```

### LucentResponseFactory (convenience)
Lucent-specific factory with JSON, redirect, and streaming helpers:

```php
use Lucent\Http\Message\Factory\LucentResponseFactory;

$factory = new LucentResponseFactory();

$json     = $factory->createJsonResponse($data, 201);
$envelope = $factory->createJsonEnvelopeResponse($data, 'OK', true, 200);
$redirect = $factory->createRedirectResponse('/home', 302);
$stream   = $factory->createEventStreamResponse($generator);
$stream   = $factory->createStreamResponse($callable, ['X-Custom' => 'value']);
```

---

## Testing

### FakeServerRequest

For tests and documentation generation, use `FakeServerRequest`:

```php
use Lucent\Facades\Faker;

$request = Faker::serverRequest('POST', ['name' => 'John'], ['X-Auth' => 'token']);
$body = $request->getParsedBody(); // ['name' => 'John']
```

### Unit Tests

New test files are available in `tests/Unit/Message/`:

- `StreamTest.php` — `Stream` implementation
- `Stream/CallbackStreamTest.php` — Callback-based streaming
- `Stream/IteratorStreamTest.php` — Iterator-based streaming
- `UriTest.php` — URI parsing and manipulation
- `ResponseTest.php` — Response creation and convenience methods
- `ServerRequestTest.php` — Server request creation and attributes
- `Factory/HttpFactoryTest.php` — PSR-17 factory
- `Factory/LucentResponseFactoryTest.php` — Convenience factory
- `Middleware/MiddlewarePipelineTest.php` — PSR-15 pipeline

---

## Architecture

```
Controller returns ResponseInterface
         │
         ▼
MiddlewarePipeline (PSR-15)
  └─ PSR-15 MiddlewareInterface → direct
         │
         ▼
Controller dispatch (RequestHandlerInterface)
         │
         ▼
Application::handleHttpRequest(ServerRequestInterface)
  ├─ getStatusCode()
  ├─ getHeaders() (string[][])
  └─ getBody() → streaming loop with flush()
```
