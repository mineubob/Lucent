<?php

namespace Tests\Support\Http;

use Lucent\Http\Client\Handler\HandlerInterface;
use Lucent\Http\Message\Factory\HttpFactory;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Test double for {@see HandlerInterface}.
 *
 * Records the request and merged options it received and returns a canned
 * response, so tests can assert what the client actually dispatched.
 */
class MockHandler implements HandlerInterface
{
    /** @var RequestInterface|null The last request received */
    public ?RequestInterface $request = null;

    /** @var array<string, mixed> The last merged options received */
    public array $options = [];

    /** @var ResponseInterface The response to return */
    public ResponseInterface $response;

    /** @var array<string, mixed> Options to reject in validateOptions */
    public array $reject = [];

    public function __construct()
    {
        $this->response = (new HttpFactory())->createResponse(200);
    }

    public function validateOptions(array $options): void
    {
        foreach ($this->reject as $key => $value) {
            if (($options[$key] ?? null) === $value) {
                throw new \InvalidArgumentException("MockHandler rejects option: {$key}");
            }
        }
    }

    public function send(RequestInterface $request, array $options): ResponseInterface
    {
        $this->request = $request;
        $this->options = $options;
        return $this->response;
    }
}