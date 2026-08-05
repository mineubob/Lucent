<?php

/**
 * Fixture router for the PHP built-in server used by Psr18ClientTest.
 *
 * Echoes back request metadata as JSON so tests can assert what the client
 * actually sent (method, URI, headers, body, basic auth, etc.).
 *
 * Behavior:
 *   - GET /                → {"method":"GET","uri":"/","headers":{...}}
 *   - POST /echo           → same shape with a "body" field (raw request body)
 *   - GET /status/404      → HTTP 404 with body "not found"
 *   - GET /slow            → sleeps 2s then responds (for timeout tests)
 *   - GET /redirect        → 302 to /final
 *   - GET /final           → {"final":true}
 *   - Any other path       → 404 JSON
 */

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$query = parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY) ?? '';

// Basic auth check: /auth/<user>/<pass> requires matching credentials.
if (preg_match('#^/auth/([^/]+)/([^/]+)$#', $path, $m)) {
    $expected = 'Basic ' . base64_encode($m[1] . ':' . $m[2]);
    $given = $_SERVER['HTTP_AUTHORIZATION']
        ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if ($given === '' && isset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'])) {
        $given = 'Basic ' . base64_encode($_SERVER['PHP_AUTH_USER'] . ':' . $_SERVER['PHP_AUTH_PW']);
    }
    if ($given !== $expected) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'unauthorized']);
        return;
    }

    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode(['auth' => 'ok']);
    return;
}

if ($path === '/status/404') {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo 'not found';
    return;
}

if ($path === '/slow') {
    sleep(2);
    http_response_code(200);
    header('Content-Type: text/plain');
    echo 'slow response';
    return;
}

if ($path === '/redirect') {
    http_response_code(302);
    header('Location: /final');
    echo 'redirecting';
    return;
}

if ($path === '/final') {
    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode(['final' => true]);
    return;
}

// Echo endpoint — return the method, URI, headers, and raw body.
if ($path === '/echo') {
    $headers = [];
    foreach ($_SERVER as $key => $value) {
        if (str_starts_with($key, 'HTTP_')) {
            $name = str_replace('_', '-', strtolower(substr($key, 5)));
            $headers[$name] = $value;
        }
    }
    if (isset($_SERVER['PHP_AUTH_USER'])) {
        $headers['php-auth-user'] = $_SERVER['PHP_AUTH_USER'];
        $headers['php-auth-pw'] = $_SERVER['PHP_AUTH_PW'];
    }

    $body = file_get_contents('php://input');

    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode([
        'method' => $method,
        'uri' => $path,
        'query' => $query,
        'headers' => $headers,
        'body' => $body,
    ]);
    return;
}

http_response_code(404);
header('Content-Type: application/json');
echo json_encode(['error' => 'not found']);
