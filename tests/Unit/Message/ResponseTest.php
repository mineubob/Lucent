<?php

namespace Tests\Unit\Message;

use Lucent\Http\Message\Response;
use Lucent\Http\Message\Stream;
use PHPUnit\Framework\TestCase;

class ResponseTest extends TestCase
{
    public function test_default_constructor(): void
    {
        $response = new Response();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('OK', $response->getReasonPhrase());
        $this->assertSame('1.1', $response->getProtocolVersion());
        $this->assertSame([], $response->getHeaders());
        $this->assertSame('', (string) $response->getBody());
    }

    public function test_with_status(): void
    {
        $response = new Response();
        $new = $response->withStatus(404);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(404, $new->getStatusCode());
        $this->assertSame('Not Found', $new->getReasonPhrase());
    }

    public function test_with_status_custom_reason(): void
    {
        $response = (new Response())->withStatus(418, "I'm a Teapot");
        $this->assertSame(418, $response->getStatusCode());
        $this->assertSame("I'm a Teapot", $response->getReasonPhrase());
    }

    public function test_with_status_invalid_code_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid status code');
        (new Response())->withStatus(99);
    }

    public function test_with_status_too_high_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid status code');
        (new Response())->withStatus(600);
    }

    public function test_with_protocol_version(): void
    {
        $response = (new Response())->withProtocolVersion('2.0');
        $this->assertSame('2.0', $response->getProtocolVersion());
    }

    public function test_with_header(): void
    {
        $response = (new Response())->withHeader('Content-Type', 'application/json');
        $this->assertSame(['application/json'], $response->getHeader('Content-Type'));
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
    }

    public function test_with_header_case_insensitive(): void
    {
        $response = (new Response())->withHeader('Content-Type', 'application/json');
        $this->assertSame(['application/json'], $response->getHeader('content-type'));
    }

    public function test_with_added_header(): void
    {
        $response = (new Response())
            ->withHeader('Accept', 'application/json')
            ->withAddedHeader('Accept', 'text/html');

        $this->assertSame(['application/json', 'text/html'], $response->getHeader('Accept'));
    }

    public function test_without_header(): void
    {
        $response = (new Response())
            ->withHeader('X-Custom', 'value')
            ->withoutHeader('X-Custom');

        $this->assertFalse($response->hasHeader('X-Custom'));
    }

    public function test_with_body(): void
    {
        $response = new Response();
        $body = Stream::fromString('Body content');
        $new = $response->withBody($body);

        $this->assertSame('', (string) $response->getBody());
        $this->assertSame('Body content', (string) $new->getBody());
    }

    public function test_json_static_factory(): void
    {
        $response = Response::json(['key' => 'value'], 201);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        $this->assertSame('{"key":"value"}', (string) $response->getBody());
    }

    public function test_with_json_body(): void
    {
        $response = (new Response())->withJsonBody(['foo' => 'bar'])->withStatus(202);

        $this->assertSame(202, $response->getStatusCode());
        $this->assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        $this->assertSame('{"foo":"bar"}', (string) $response->getBody());
    }

    public function test_with_json_envelope(): void
    {
        $response = (new Response())->withJsonEnvelope(['data' => 'test'], 'Success', true, 200);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));

        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame('Success', $body['message']);
        $this->assertTrue($body['outcome']);
        $this->assertSame(200, $body['status']);
        $this->assertSame(['data' => 'test'], $body['content']);
    }

    public function test_with_redirect(): void
    {
        $response = (new Response())->withRedirect('/new-location', 301);

        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame('/new-location', $response->getHeaderLine('Location'));
    }

    public function test_with_stream(): void
    {
        $response = (new Response())->withStream(function () {
            return 'streamed';
        });

        $this->assertSame('streamed', (string) $response->getBody());
    }

    public function test_with_event_stream(): void
    {
        $response = (new Response())->withEventStream((function () {
            yield 'event data';
        })());

        $this->assertSame('text/event-stream', $response->getHeaderLine('Content-Type'));
        $this->assertSame('no-cache, no-store, must-revalidate', $response->getHeaderLine('Cache-Control'));
        $this->assertSame('no', $response->getHeaderLine('X-Accel-Buffering'));
        $this->assertSame('keep-alive', $response->getHeaderLine('Connection'));
    }

    public function test_with_event_stream_accepts_traversable(): void
    {
        $response = (new Response())->withEventStream(new \ArrayIterator(['event data']));

        $this->assertSame('text/event-stream', $response->getHeaderLine('Content-Type'));
        $this->assertSame('event data', (string) $response->getBody());
    }

    public function test_immutability(): void
    {
        $response = new Response();
        $response->withStatus(404);
        $response->withHeader('X-Test', 'value');
        $response->withBody(Stream::fromString('test'));

        // Original should be unchanged
        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse($response->hasHeader('X-Test'));
        $this->assertSame('', (string) $response->getBody());
    }

    public function test_get_reason_phrase_for_unknown_code(): void
    {
        $response = (new Response())->withStatus(599);
        $this->assertSame('', $response->getReasonPhrase());
    }
}