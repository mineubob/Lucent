<?php
namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Global middleware fixture that throws an exception.
 *
 * Used to assert that an exception thrown by global middleware itself is
 * converted to a 500 response rather than escaping the request handler.
 */
class GlobalThrowingMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        throw new \RuntimeException('Global middleware exploded');
    }
}