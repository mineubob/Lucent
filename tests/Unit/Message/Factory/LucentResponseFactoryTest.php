<?php

namespace Tests\Unit\Message\Factory;

use Lucent\Http\Message\Factory\LucentResponseFactory;
use Lucent\Http\Message\Stream\IteratorStream;
use Lucent\Http\Message\Stream\LazyStream;
use PHPUnit\Framework\TestCase;

class LucentResponseFactoryTest extends TestCase
{
    private LucentResponseFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->factory = new LucentResponseFactory();
    }

    public function test_create_json_response(): void
    {
        $response = $this->factory->createJsonResponse(['key' => 'value'], 201);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        $this->assertSame('{"key":"value"}', (string) $response->getBody());
    }

    public function test_create_json_envelope_response(): void
    {
        $response = $this->factory->createJsonEnvelopeResponse(
            ['data' => 'test'],
            'Custom message',
            false,
            400
        );

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));

        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame('Custom message', $body['message']);
        $this->assertFalse($body['outcome']);
        $this->assertSame(400, $body['status']);
        $this->assertSame(['data' => 'test'], $body['content']);
    }

    public function test_create_redirect_response(): void
    {
        $response = $this->factory->createRedirectResponse('/new-location', 301);

        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame('/new-location', $response->getHeaderLine('Location'));
    }

    public function test_create_redirect_response_default_status(): void
    {
        $response = $this->factory->createRedirectResponse('/temporary');

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/temporary', $response->getHeaderLine('Location'));
    }

    public function test_create_event_stream_response_with_generator(): void
    {
        $response = $this->factory->createEventStreamResponse((function () {
            yield 'event data';
        })());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/event-stream', $response->getHeaderLine('Content-Type'));
        $this->assertSame('no-cache, no-store, must-revalidate', $response->getHeaderLine('Cache-Control'));
        $this->assertInstanceOf(IteratorStream::class, $response->getBody());
        $this->assertSame('event data', (string) $response->getBody());
    }

    public function test_create_event_stream_response_with_traversable(): void
    {
        $response = $this->factory->createEventStreamResponse(new \ArrayIterator(['event data']));

        $this->assertSame('text/event-stream', $response->getHeaderLine('Content-Type'));
        $this->assertInstanceOf(IteratorStream::class, $response->getBody());
        $this->assertSame('event data', (string) $response->getBody());
    }

    public function test_create_stream_response_with_callable(): void
    {
        $response = $this->factory->createStreamResponse(function () {
            return 'streamed content';
        });

        $this->assertSame(200, $response->getStatusCode());
        $this->assertInstanceOf(LazyStream::class,$response->getBody());
        $this->assertSame('streamed content', (string) $response->getBody());
    }

    public function test_create_stream_response_with_traversable(): void
    {
        $response = $this->factory->createStreamResponse(new \ArrayIterator(['a', 'b', 'c']));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertInstanceOf(IteratorStream::class, $response->getBody());
        $this->assertSame('abc', (string) $response->getBody());
    }

    public function test_create_stream_response_with_custom_headers(): void
    {
        $response = $this->factory->createStreamResponse(
            function () { return 'data'; },
            ['X-Custom' => 'value']
        );

        $this->assertSame('value', $response->getHeaderLine('X-Custom'));
    }
}