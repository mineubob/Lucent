<?php

namespace Lucent\Http\Middleware;

use Lucent\Http\Message\Adapter\RequestAdapter;
use Lucent\Middleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Wraps an old Lucent\Middleware subclass as a PSR-15 middleware.
 *
 * The old middleware contract is handle(Request $request): Request — it
 * transforms the request and returns it. This adapter:
 * 1. Converts PSR-7 ServerRequest → old Request via RequestAdapter
 * 2. Calls the old middleware's handle()
 * 3. Converts the old Request back to PSR-7
 * 4. Passes the (possibly modified) request to the next handler
 * 5. Emits E_USER_DEPRECATED
 *
 * @deprecated Convert to PSR-15 MiddlewareInterface directly
 */
class LegacyMiddlewareAdapter implements MiddlewareInterface
{
    private Middleware $legacyMiddleware;

    private static bool $deprecationEmitted = false;

    public function __construct(Middleware $legacyMiddleware)
    {
        $this->legacyMiddleware = $legacyMiddleware;

        if (! self::$deprecationEmitted) {
            trigger_error(
                get_class($legacyMiddleware) . ' extends Lucent\Middleware which is deprecated. Implement Psr\Http\Server\MiddlewareInterface instead.',
                E_USER_DEPRECATED
            );
            self::$deprecationEmitted = true;
        }
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Convert PSR-7 → old Request
        $oldRequest = RequestAdapter::fromPsr7($request);

        // Run old middleware (returns modified old Request)
        $modifiedRequest = $this->legacyMiddleware->handle($oldRequest);

        // Convert back to PSR-7
        $psr7Request = RequestAdapter::toPsr7($modifiedRequest);

        // Continue to next handler
        return $handler->handle($psr7Request);
    }
}