<?php

namespace Lucent\Http\Client\Exception;

use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestInterface;

/**
 * PSR-18 exception for when a request failed.
 *
 * Examples: the request is invalid, or a runtime request error occurred
 * (e.g. an unreadable body stream). Thrown by {@see \Lucent\Http\Client\HttpClient}
 * when the request itself prevents the transfer from completing.
 */
class RequestException extends \RuntimeException implements RequestExceptionInterface
{
    /** @var RequestInterface The request that failed */
    private RequestInterface $request;

    public function __construct(string $message, RequestInterface $request, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->request = $request;
    }

    /**
     * Returns the request that failed.
     */
    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
