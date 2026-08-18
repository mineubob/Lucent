[Home](../README.md)

# Error Handling

Lucent provides a layered error handling system that covers HTTP errors, debug information, exception render callbacks, custom error pages, and fallback responses.

## How Errors Are Handled

When something goes wrong during a request — a missing route, an invalid controller, a thrown exception — Lucent catches it and produces a structured JSON response by default. The response always includes an `outcome`, `status`, and `message` field.

In production, the message is always the generic status message for that HTTP code. In debug mode, the response includes the full underlying cause.

### Debug Mode

Set `DEBUG=true` in your `.env` file to enable debug output:

```env
DEBUG=true
```

When debug is enabled and an error occurs, the response will include an `errors.exception` block containing the original exception's message, file, line, and full stack trace:

```json
{
  "outcome": false,
  "status": 500,
  "message": "We're experiencing technical difficulties.",
  "errors": {
    "exception": {
      "message": "File not found: /app/routes/missing.php",
      "code": 0,
      "file": "/app/src/Http/HttpRouter.php",
      "line": 47,
      "trace": [...]
    }
  }
}
```

In production (`DEBUG` unset or `false`), the `errors` block is omitted entirely and only the safe generic message is returned.

## Exception Render Callbacks

For programmatic, type-driven error handling you can register render callbacks on the shared exception manager. Each callback is dispatched by the type-hint of its first parameter, and the first callback to return a `ResponseInterface` wins. A callback that returns `null` falls through to the next callback, then to the framework default.

```php
use Lucent\Facades\App;
use Lucent\Http\Exceptions\HttpException;

App::exceptions()->render(function (HttpException $e) {
    // Return a Response for any HttpException, or null to fall through.
    return null;
});
```

Render callbacks take priority over every other error mechanism below.

## Custom Error Pages

You can register a custom response for any HTTP status code using `Route::error()`. This is typically done inside a routes file. Your custom error response can be any PSR-7 `ResponseInterface` — create it inline:

```php
use Lucent\Facades\Route;
use Lucent\Facades\FileSystem;
use Lucent\Http\Message\Response;
use Lucent\Http\Message\Stream;

$views = FileSystem::rootPath() . '/App/Views';

Route::error(404, (new Response())->withStatus(404)
    ->withBody(Stream::fromString(file_get_contents($views . '/404.html')))
    ->withHeader('Content-Type', 'text/html; charset=utf-8')
);
Route::error(500, (new Response())->withStatus(500)
    ->withBody(Stream::fromString(file_get_contents($views . '/500.html')))
    ->withHeader('Content-Type', 'text/html; charset=utf-8')
);
```

When Lucent encounters an unhandled exception (thrown `HttpException` or any other `Throwable`) during dispatch, it checks for a registered error page for that status code. If one exists, it's returned instead of the default JSON response. Custom error pages take priority over all other error handling.

| Error scenario | Error page applies? |
|---|---|
| Controller throws `HttpException` | ✅ Yes — caught by dispatch, routed through `responseWithError()` |
| Any other `Throwable` in dispatch | ✅ Yes — caught as 500, routed through `responseWithError()` |
| Middleware returns a `Response` directly | ❌ No — middleware short-circuits before dispatch |
| Invalid route / controller not found | ✅ Yes — caught as `HttpException` during dispatch |

## Fallback Response

A fallback response is returned when no route matches and no custom 404 error page is registered. This is useful for single-page applications where you want all unmatched routes to serve your frontend entry point.

```php
use Lucent\Facades\Route;
use Lucent\Facades\FileSystem;
use Lucent\Http\Message\Response;
use Lucent\Http\Message\Stream;

$views = FileSystem::rootPath() . '/App/Views';

Route::fallback((new Response())
    ->withBody(Stream::fromString(file_get_contents($views . '/index.html')))
    ->withHeader('Content-Type', 'text/html; charset=utf-8')
);
```

The fallback only applies to 404 (route not found) responses. It does not affect other error codes.

## Priority Order

When an error occurs, Lucent resolves the response in this order:

1. **Exception render callback** — the first `Exceptions::render()` callback whose type-hint matches the thrown exception and returns a `ResponseInterface`
2. **Registered error page** — `Route::error($code, $response)` for the matching status code
3. **Fallback response** — `Route::fallback($response)` for 404s only
4. **Default JSON response** — with debug info if `DEBUG=true`, otherwise the generic message

## Throwing HTTP Exceptions

Inside your controllers or middleware you can throw an `HttpException` to produce a specific HTTP error response. The second argument is the message shown in debug mode, and the third is the underlying cause:

```php
use Lucent\Http\Exceptions\HttpException;
use Lucent\Http\HttpStatus;

// Simple status error
throw new HttpException(HttpStatus::NOT_FOUND);

// With a cause for debug output
throw new HttpException(
    HttpStatus::INTERNAL_SERVER_ERROR,
    "Route file not found",
    new \RuntimeException("File not found: $path")
);
```

In production the user sees the generic status message. In debug mode they see the underlying `RuntimeException`'s message, file, line, and trace.