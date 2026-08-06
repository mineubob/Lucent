<?php

namespace Unit\Message;

use Lucent\Http\Message\ServerRequest;
use Lucent\Http\Message\Uri;
use PHPUnit\Framework\TestCase;

class ServerRequestTest extends TestCase
{
    public function test_default_constructor(): void
    {
        $request = new ServerRequest();
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('/', $request->getRequestTarget());
        $this->assertSame('1.1', $request->getProtocolVersion());
    }

    public function test_constructor_with_method_and_uri(): void
    {
        $uri = Uri::fromString('https://example.com/test');
        $request = new ServerRequest('POST', $uri);

        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/test', $request->getRequestTarget());
    }

    public function test_get_server_params(): void
    {
        $request = new ServerRequest('GET', null, ['REQUEST_METHOD' => 'GET']);
        $this->assertSame(['REQUEST_METHOD' => 'GET'], $request->getServerParams());
    }

    public function test_with_method(): void
    {
        $request = new ServerRequest('GET');
        $new = $request->withMethod('POST');

        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('POST', $new->getMethod());
    }

    public function test_with_uri(): void
    {
        $request = new ServerRequest();
        $uri = Uri::fromString('https://example.com/new');
        $new = $request->withUri($uri);

        $this->assertSame('/', $request->getRequestTarget());
        $this->assertSame('/new', $new->getRequestTarget());
    }

    public function test_with_uri_updates_host_header(): void
    {
        $uri = Uri::fromString('https://example.com/path');
        $request = (new ServerRequest())->withUri($uri);

        $this->assertSame('example.com', $request->getHeaderLine('Host'));
    }

    public function test_cookie_params(): void
    {
        $request = new ServerRequest();
        $new = $request->withCookieParams(['session' => 'abc123']);

        $this->assertSame([], $request->getCookieParams());
        $this->assertSame(['session' => 'abc123'], $new->getCookieParams());
    }

    public function test_query_params(): void
    {
        $request = new ServerRequest();
        $new = $request->withQueryParams(['page' => '1']);

        $this->assertSame([], $request->getQueryParams());
        $this->assertSame(['page' => '1'], $new->getQueryParams());
    }

    public function test_parsed_body(): void
    {
        $request = new ServerRequest();
        $new = $request->withParsedBody(['field' => 'value']);

        $this->assertNull($request->getParsedBody());
        $this->assertSame(['field' => 'value'], $new->getParsedBody());
    }

    public function test_parsed_body_rejects_invalid_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ServerRequest())->withParsedBody('invalid string');
    }

    public function test_uploaded_files(): void
    {
        $request = new ServerRequest();
        $this->assertSame([], $request->getUploadedFiles());
    }

    public function test_attributes(): void
    {
        $request = new ServerRequest();
        $new = $request->withAttribute('key', 'value');

        $this->assertNull($request->getAttribute('key'));
        $this->assertSame('value', $new->getAttribute('key'));
    }

    public function test_get_attribute_default(): void
    {
        $request = new ServerRequest();
        $this->assertSame('default', $request->getAttribute('nonexistent', 'default'));
    }

    public function test_without_attribute(): void
    {
        $request = (new ServerRequest())->withAttribute('key', 'value');
        $new = $request->withoutAttribute('key');

        $this->assertSame('value', $request->getAttribute('key'));
        $this->assertNull($new->getAttribute('key'));
    }

    public function test_get_request_target_defaults_to_slash(): void
    {
        $request = new ServerRequest();
        $this->assertSame('/', $request->getRequestTarget());
    }

    public function test_with_request_target(): void
    {
        $request = (new ServerRequest())->withRequestTarget('*');
        $this->assertSame('*', $request->getRequestTarget());
    }

    public function test_from_globals_creates_request(): void
    {
        $server = [
            'REQUEST_METHOD' => 'POST',
            'HTTP_HOST' => 'example.com',
            'SERVER_PORT' => '80',
            'REQUEST_URI' => '/submit?q=1',
            'CONTENT_TYPE' => 'application/json',
        ];

        $request = ServerRequest::fromGlobals($server, ['q' => '1'], ['field' => 'value']);

        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('example.com', $request->getHeaderLine('Host'));
        $this->assertSame('/submit?q=1', $request->getRequestTarget());
        $this->assertSame(['field' => 'value'], $request->getParsedBody());
        $this->assertSame(['q' => '1'], $request->getQueryParams());
    }

    public function test_get_uri_returns_uri_interface(): void
    {
        $request = new ServerRequest();
        $this->assertInstanceOf(\Psr\Http\Message\UriInterface::class, $request->getUri());
    }
}