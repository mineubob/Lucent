<?php

namespace Lucent\Http\Message\Adapter;

use Lucent\Http\Request;
use Lucent\Http\Message\ServerRequest;
use Lucent\Http\RouteInfo;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Converts between the old Lucent\Http\Request and PSR-7 ServerRequestInterface.
 *
 * Used by LegacyMiddlewareAdapter to bridge old middleware with the new PSR-15 pipeline.
 */
class RequestAdapter
{
    /**
     * Convert a PSR-7 ServerRequest to the old Lucent\Http\Request.
     *
     * The old Request reads from superglobals in its constructor. We create it,
     * then override its internal data using public methods.
     */
    public static function fromPsr7(ServerRequestInterface $psr7): Request
    {
        $old = new Request();

        // Override input data
        foreach ($psr7->getQueryParams() as $key => $value) {
            if (is_string($value) || is_array($value)) {
                $old->setInput($key, $value);
            }
        }

        $parsedBody = $psr7->getParsedBody();
        if (is_array($parsedBody)) {
            foreach ($parsedBody as $key => $value) {
                if (is_string($value) || is_array($value)) {
                    $old->setInput($key, $value);
                }
            }
        }

        // Set headers
        foreach ($psr7->getHeaders() as $name => $values) {
            $old->setHeader($name, implode(', ', $values));
        }

        // PSR-7 attributes carry Lucent-specific data
        $routeInfo = $psr7->getAttribute('routeInfo');
        if ($routeInfo instanceof RouteInfo) {
            $old->setRouteInfo($routeInfo);
        }

        $urlVars = $psr7->getAttribute('urlVars', []);
        if (is_array($urlVars) && $urlVars !== []) {
            $old->setUrlVars($urlVars);
        }

        $context = $psr7->getAttribute('context', []);
        if (is_array($context)) {
            foreach ($context as $key => $value) {
                $old->context[$key] = $value;
            }
        }

        return $old;
    }

    /**
     * Convert the old Lucent\Http\Request to a PSR-7 ServerRequest.
     *
     * Lucent-specific data (routeInfo, urlVars, context) is stored as PSR-7 attributes.
     */
    public static function toPsr7(Request $old): ServerRequestInterface
    {
        $psr7 = ServerRequest::fromGlobals();

        // Attach Lucent-specific data as PSR-7 attributes
        if ($old->routeInfo !== null) {
            $psr7 = $psr7->withAttribute('routeInfo', $old->routeInfo);
            $psr7 = $psr7->withAttribute('urlVars', $old->routeInfo->parameters);
        }

        if ($old->context !== []) {
            $psr7 = $psr7->withAttribute('context', $old->context);
        }

        return $psr7;
    }
}