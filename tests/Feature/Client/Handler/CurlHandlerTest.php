<?php

namespace Tests\Feature\Client\Handler;

use Lucent\Http\Client\Exception\NetworkException;
use Lucent\Http\Client\Handler\CurlHandler;
use Lucent\Http\Message\Factory\HttpFactory;
use Lucent\Http\Message\Stream;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Tests\Support\Http\StartsFixtureServer;

class CurlHandlerTest extends TestCase
{
    use StartsFixtureServer;

    private CurlHandler $handler;
    private HttpFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->handler = new CurlHandler();
        $this->factory = new HttpFactory();
    }

    private function request(string $method, string $uri): RequestInterface
    {
        return $this->factory->createRequest($method, self::$baseUrl . $uri);
    }

    public function test_basic_get(): void
    {
        $response = $this->handler->send($this->request('GET', '/echo'), []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('1.1', $response->getProtocolVersion());

        $payload = json_decode((string) $response->getBody(), true);
        $this->assertSame('GET', $payload['method']);
        $this->assertSame('/echo', $payload['uri']);
    }

    public function test_sends_body_via_readfunction(): void
    {
        $request = $this->request('POST', '/echo')->withBody(Stream::fromString('hello world'));

        $response = $this->handler->send($request, []);

        $payload = json_decode((string) $response->getBody(), true);
        $this->assertSame('hello world', $payload['body']);
    }

    public function test_sink_receives_body(): void
    {
        $sink = Stream::fromResource(fopen('php://temp', 'w+'));

        $response = $this->handler->send($this->request('GET', '/echo'), ['sink' => $sink]);

        $this->assertSame(200, $response->getStatusCode());

        $sink->rewind();
        $payload = json_decode((string) $sink, true);
        $this->assertSame('/echo', $payload['uri']);
    }

    public function test_progress_callback_fires(): void
    {
        $calls = 0;
        $response = $this->handler->send($this->request('GET', '/echo'), [
            'progress' => function (...$args) use (&$calls) {
                $calls++;
            },
        ]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertGreaterThan(0, $calls);
    }

    public function test_rejects_conflicting_curl_option(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->handler->validateOptions([
            'curl' => [CURLOPT_WRITEFUNCTION => 'foo'],
        ]);
    }

    public function test_validate_options_rejects_non_callable_progress(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->handler->validateOptions(['progress' => 'not-a-callable']);
    }

    public function test_validate_options_accepts_valid_options(): void
    {
        $this->handler->validateOptions([
            'timeout' => 5,
            'verify_ssl' => true,
            'progress' => function () {},
            'curl' => [CURLOPT_FRESH_CONNECT => true],
        ]);
        $this->addToAssertionCount(1);
    }

    public function test_network_exception_on_closed_port(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $address = stream_socket_get_name($socket, false);
        $port = (int) substr($address, strrpos($address, ':') + 1);
        fclose($socket);

        $request = $this->factory->createRequest('GET', "http://127.0.0.1:{$port}/");

        $this->expectException(NetworkException::class);
        $this->handler->send($request, []);
    }
}