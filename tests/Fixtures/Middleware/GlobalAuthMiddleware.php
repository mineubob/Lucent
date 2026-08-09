<?php
namespace App\Middleware;

use Lucent\Http\Exceptions\HttpException;
use Lucent\Http\HttpStatus;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Global middleware fixture that throws an HttpException.
 *
 * Used to assert that an HttpException thrown by global middleware itself
 * keeps its status (e.g. 401) rather than being converted to a 500.
 */
class GlobalAuthMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        throw new HttpException(HttpStatus::UNAUTHORIZED, 'Unauthorized');
    }
}