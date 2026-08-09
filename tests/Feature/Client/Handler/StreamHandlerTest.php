<?php

namespace Tests\Feature\Client\Handler;

use Lucent\Http\Client\Exception\NetworkException;
use Lucent\Http\Client\Handler\StreamHandler;
use Lucent\Http\Message\Factory\HttpFactory;
use Lucent\Http\Message\Stream;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Tests\Support\Http\StartsFixtureServer;

class StreamHandlerTest extends TestCase
{
    use StartsFixtureServer;

    private StreamHandler $handler;
    private HttpFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->handler = new StreamHandler();
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

    public function test_sends_body(): void
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

    public function test_stream_option_returns_live_body(): void
    {
        $response = $this->handler->send($this->request('GET', '/stream'), ['stream' => true]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('3', $response->getHeaderLine('X-Chunks'));

        // The body is the live socket: read it incrementally.
        $body = $response->getBody();
        $this->assertFalse($body->isSeekable());

        $contents = '';
        while (!$body->eof()) {
            $contents .= $body->read(6);
        }

        $this->assertSame('chunk1chunk2chunk3', $contents);
    }

    public function test_rejects_curl_option(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->handler->validateOptions([
            'curl' => [CURLOPT_FOLLOWLOCATION => true],
        ]);
    }

    public function test_validate_options_rejects_progress(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->handler->validateOptions(['progress' => function () {}]);
    }

    public function test_validate_options_accepts_valid_options(): void
    {
        $this->handler->validateOptions([
            'timeout' => 5,
            'verify_ssl' => true,
            'stream' => true,
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