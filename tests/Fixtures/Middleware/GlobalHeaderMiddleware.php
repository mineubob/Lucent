<?php
namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Global middleware fixture that adds a header to every response.
 *
 * Used to assert that global middleware runs for every request, including
 * routing failures (404/403) and dispatch errors (500).
 */
class GlobalHeaderMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        return $response->withHeader('X-Global-Middleware', 'ran');
    }
}