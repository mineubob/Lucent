<?php

namespace Lucent\Http\Client\Exception;

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Lucent\Http\Client\Exception\RequestException;

/**
 * PSR-18 exception for transport-level failures.
 *
 * Thrown when the request cannot be completed because of network issues —
 * e.g. the target host cannot be resolved, the connection failed, or the
 * request timed out. There is no response object as no response was received.
 */
class NetworkException extends RequestException implements NetworkExceptionInterface
{
    public function __construct(string $message, RequestInterface $request, ?\Throwable $previous = null)
    {
        parent::__construct($message, $request, $previous);
    }
}
