<?php

/**
 * Lucent development server router.
 *
 * This script is passed to PHP's built-in server (`php -S ... server.php`) by
 * the `serve` command. It emulates Apache's "mod_rewrite" behaviour so that
 * framework routes (e.g. /users) work instead of returning a 404, while real
 * static files are still served directly.
 *
 * The `serve` command launches the server with the document root as its
 * working directory, so `getcwd()` here is the public directory.
 *
 * To customise this behaviour (e.g. SPA-shell fallback), publish a `server.php`
 * to your project root — the `serve` command will use it instead of this file.
 */

$publicPath = getcwd();

$uri = urldecode(
    parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/'
);

// Serve real static files directly. Returning `false` hands control back to
// the built-in server, which serves the file without invoking the framework.
// PHP logs these requests itself (e.g. `[200]: GET /style.css`).
if ($uri !== '/' && file_exists($publicPath . $uri)) {
    return false;
}

// PHP's built-in server does NOT log requests that are handled by the router
// (it only logs connection open/close). So we write a request line ourselves,
// so framework routes are visible in the terminal. Static files above are
// already logged by PHP, so we skip them.
$formattedDateTime = date('D M j H:i:s Y');
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$remoteAddress = ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1') . ':' . ($_SERVER['REMOTE_PORT'] ?? '');
file_put_contents('php://stdout', "[$formattedDateTime] $remoteAddress [$requestMethod] URI: $uri\n");

// Forward everything else to the framework's front controller.
require_once $publicPath . '/index.php';