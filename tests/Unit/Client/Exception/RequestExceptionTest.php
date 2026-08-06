<?php

namespace Tests\Unit\Client\Exception;

use Lucent\Http\Client\Exception\NetworkException;
use Lucent\Http\Client\Exception\RequestException;
use Lucent\Http\Message\Request;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestInterface;
use PHPUnit\Framework\TestCase;

class RequestExceptionTest extends TestCase
{
    private RequestInterface $request;

    protected function setUp(): void
    {
        parent::setUp();
        $this->request = new Request('GET', \Lucent\Http\Message\Uri::fromString('https://api.example.com/test'));
    }

    public function test_implements_request_exception_interface(): void
    {
        $exception = new RequestException('Request failed', $this->request);

        $this->assertInstanceOf(RequestExceptionInterface::class, $exception);
        $this->assertInstanceOf(ClientExceptionInterface::class, $exception);
        $this->assertInstanceOf(\RuntimeException::class, $exception);
    }

    public function test_get_request_returns_stored_request(): void
    {
        $exception = new RequestException('Request failed', $this->request);

        $this->assertSame($this->request, $exception->getRequest());
    }

    public function test_network_exception_hierarchy(): void
    {
        $exception = new NetworkException('Connection refused', $this->request);

        $this->assertInstanceOf(NetworkExceptionInterface::class, $exception);
        $this->assertInstanceOf(RequestException::class, $exception);
        $this->assertInstanceOf(RequestExceptionInterface::class, $exception);
        $this->assertSame($this->request, $exception->getRequest());
    }

    public function test_message_and_previous_are_passed_through(): void
    {
        $previous = new \RuntimeException('previous');
        $exception = new RequestException('Request failed', $this->request, $previous);

        $this->assertSame('Request failed', $exception->getMessage());
        $this->assertSame($previous, $exception->getPrevious());
    }
}
