<?php
namespace App\Middleware;

use Lucent\Http\Message\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Global middleware fixture that short-circuits the pipeline with its own
 * response, never calling the handler.
 *
 * Used to assert that global middleware can replace an error response (e.g.
 * a 404) with its own response.
 */
class GlobalShortCircuitMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return (new Response())->withJsonEnvelope([], 'short-circuited', true, 200);
    }
}