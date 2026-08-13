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
if ($uri !== '/' && file_exists($publicPath . $uri)) {
    return false;
}

// Forward everything else to the framework's front controller.
require_once $publicPath . '/index.php';