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
     * Cursor into $middleware. Using an index instead of array_shift() keeps
     * the pipeline's middleware list intact, so a pipeline instance can be
     * reused (e.g. a fresh clone per dispatch) without losing entries.
     */
    private int $position = 0;

    /**
     * @param \Psr\Http\Server\MiddlewareInterface[] $middleware
     */
    public function __construct(array $middleware, RequestHandlerInterface $fallbackHandler)
    {
        $this->middleware = array_values($middleware);
        $this->fallbackHandler = $fallbackHandler;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (!isset($this->middleware[$this->position])) {
            return $this->fallbackHandler->handle($request);
        }

        $middleware = $this->middleware[$this->position];

        // Advance a clone so nested handle() calls (from middleware calling
        // $handler->handle()) each get their own cursor — the pipeline
        // instance itself is not mutated.
        $next = clone $this;
        $next->position++;

        return $middleware->process($request, $next);
    }
}