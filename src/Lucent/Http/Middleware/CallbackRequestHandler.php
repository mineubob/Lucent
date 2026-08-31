<?php
declare(strict_types=1);


namespace Lucent\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * PSR-15 RequestHandlerInterface adapter wrapping a plain callable.
 *
 * PSR-15 requires the final fallback handler to implement
 * RequestHandlerInterface. This adapter wraps a controller dispatch
 * callable so it can be the terminal handler in a MiddlewarePipeline.
 */
class CallbackRequestHandler implements RequestHandlerInterface
{
    /** @var callable Invoked with (ServerRequestInterface $request): ResponseInterface */
    private $callback;

    /**
     * @param callable $callback Invoked with (ServerRequestInterface $request): ResponseInterface
     */
    public function __construct(callable $callback)
    {
        $this->callback = $callback;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return call_user_func($this->callback, $request);
    }
}