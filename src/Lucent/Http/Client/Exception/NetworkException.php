<?php
declare(strict_types=1);


namespace Lucent\Http\Client\Exception;

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;

/**
 * PSR-18 exception for transport-level failures.
 *
 * Thrown when the request cannot be completed because of network issues —
 * e.g. the target host cannot be resolved, the connection failed, or the
 * request timed out. There is no response object as no response was received.
 *
 * Deliberately does NOT extend RequestException: a network failure is not a
 * "request is invalid" error, so the two hierarchies are kept separate.
 */
class NetworkException extends \RuntimeException implements NetworkExceptionInterface
{
    /** @var RequestInterface The request that could not be completed */
    private RequestInterface $request;

    public function __construct(string $message, RequestInterface $request, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->request = $request;
    }

    /**
     * Returns the request that could not be completed.
     */
    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
