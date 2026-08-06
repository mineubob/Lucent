<?php

namespace Lucent\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * PSR-15 middleware pipeline.
 *
 * Chains PSR-15 middleware, with the final fallback handler being
 * the controller dispatch.
 */
class MiddlewarePipeline implements RequestHandlerInterface
{
    /** @var array<int, \Psr\Http\Server\MiddlewareInterface> */
    private array $middleware = [];

    private RequestHandlerInterface $fallbackHandler;

    /**
     * @param \Psr\Http\Server\MiddlewareInterface[] $middleware
     */
    public function __construct(array $middleware, RequestHandlerInterface $fallbackHandler)
    {
        $this->middleware = $middleware;
        $this->fallbackHandler = $fallbackHandler;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (empty($this->middleware)) {
            return $this->fallbackHandler->handle($request);
        }

        $middleware = array_shift($this->middleware);
        return $middleware->process($request, $this);
    }
}