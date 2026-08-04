<?php

namespace Lucent\Http\Middleware;

use Lucent\Http\HttpResponse;
use Lucent\Http\Message\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Wraps the controller dispatch (invoking the resolved controller method)
 * as a PSR-15 RequestHandlerInterface.
 *
 * Detects the return type:
 * - ResponseInterface → passthrough (no conversion)
 * - HttpResponse → Response::fromLegacy() (auto-converted, emits deprecation)
 * - EventStreamResponse → also flows through fromLegacy() (callback wrapped in CallbackStream)
 */
class LegacyHandlerAdapter implements RequestHandlerInterface
{
    /** @var callable */
    private $dispatchCallback;

    /**
     * @param callable $dispatchCallback Invoked with (ServerRequestInterface $request): ResponseInterface|HttpResponse
     */
    public function __construct(callable $dispatchCallback)
    {
        $this->dispatchCallback = $dispatchCallback;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $result = call_user_func($this->dispatchCallback, $request);

        if ($result instanceof ResponseInterface) {
            return $result;
        }

        if ($result instanceof HttpResponse) {
            return Response::fromLegacy($result);
        }

        throw new \RuntimeException(sprintf(
            'Controller must return a %s or %s, got %s.',
            ResponseInterface::class,
            HttpResponse::class,
            is_object($result) ? get_class($result) : gettype($result)
        ));
    }
}