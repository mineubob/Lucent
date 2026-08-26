<?php

namespace Tests\Unit\Message;

use Lucent\Http\Message\ServerRequest;
use Lucent\Http\Message\Uri;
use PHPUnit\Framework\TestCase;

class ServerRequestTest extends TestCase
{
    public function test_default_constructor(): void
    {
        $request = ServerRequest::create();
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('/', $request->getRequestTarget());
        $this->assertSame('1.1', $request->getProtocolVersion());
    }

    public function test_constructor_with_method_and_uri(): void
    {
        $request = ServerRequest::create('POST', 'https://example.com/test');

        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/test', $request->getRequestTarget());
    }

    public function test_get_server_params(): void
    {
        $request = ServerRequest::create('GET', '/', server: ['REQUEST_METHOD' => 'GET']);
        $this->assertSame(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'SERVER_PROTOCOL' => 'HTTP/1.1', 'HTTP_HOST' => 'localhost'], $request->getServerParams());
    }

    public function test_with_method(): void
    {
        $request = ServerRequest::create('GET');
        $new = $request->withMethod('POST');

        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('POST', $new->getMethod());
    }

    public function test_with_uri(): void
    {
        $request = ServerRequest::create();
        $uri = Uri::fromString('https://example.com/new');
        $new = $request->withUri($uri);

        $this->assertSame('/', $request->getRequestTarget());
        $this->assertSame('/new', $new->getRequestTarget());
    }

    public function test_with_uri_updates_host_header(): void
    {
        $uri = Uri::fromString('https://example.com/path');
        $request = ServerRequest::create()->withUri($uri);

        $this->assertSame('example.com', $request->getHeaderLine('Host'));
    }

    public function test_cookie_params(): void
    {
        $request = ServerRequest::create();
        $new = $request->withCookieParams(['session' => 'abc123']);

        $this->assertSame([], $request->getCookieParams());
        $this->assertSame(['session' => 'abc123'], $new->getCookieParams());
    }

    public function test_query_params(): void
    {
        $request = ServerRequest::create();
        $new = $request->withQueryParams(['page' => '1']);

        $this->assertSame([], $request->getQueryParams());
        $this->assertSame(['page' => '1'], $new->getQueryParams());
    }

    public function test_parsed_body(): void
    {
        $request = ServerRequest::create();
        $new = $request->withParsedBody(['field' => 'value']);

        $this->assertNull($request->getParsedBody());
        $this->assertSame(['field' => 'value'], $new->getParsedBody());
    }

    public function test_parsed_body_rejects_invalid_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Parsed body must be null, an array, or an object');
        ServerRequest::create()->withParsedBody('invalid string');
    }

    public function test_uploaded_files(): void
    {
        $request = ServerRequest::create();
        $this->assertSame([], $request->getUploadedFiles());
    }

    public function test_attributes(): void
    {
        $request = ServerRequest::create();
        $new = $request->withAttribute('key', 'value');

        $this->assertNull($request->getAttribute('key'));
        $this->assertSame('value', $new->getAttribute('key'));
    }

    public function test_get_attribute_default(): void
    {
        $request = ServerRequest::create();
        $this->assertSame('default', $request->getAttribute('nonexistent', 'default'));
    }

    public function test_without_attribute(): void
    {
        $request = ServerRequest::create()->withAttribute('key', 'value');
        $new = $request->withoutAttribute('key');

        $this->assertSame('value', $request->getAttribute('key'));
        $this->assertNull($new->getAttribute('key'));
    }

    public function test_get_request_target_defaults_to_slash(): void
    {
        $request = ServerRequest::create();
        $this->assertSame('/', $request->getRequestTarget());
    }

    public function test_with_request_target(): void
    {
        $request = ServerRequest::create()->withRequestTarget('*');
        $this->assertSame('*', $request->getRequestTarget());
    }

    public function test_create_builds_request_from_explicit_values(): void
    {
        $request = ServerRequest::create(
            'POST',
            '/submit',
            query: ['q' => '1'],
            body: ['field' => 'value'],
            headers: ['Host' => 'example.com'],
        );

        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('example.com', $request->getHeaderLine('Host'));
        $this->assertSame('/submit', $request->getRequestTarget());
        $this->assertSame(['field' => 'value'], $request->getParsedBody());
        $this->assertSame(['q' => '1'], $request->getQueryParams());
    }

    public function test_create_parses_query_string_from_uri(): void
    {
        // If a query string is in the URI, it's parsed and merged with $query
        $request = ServerRequest::create('GET', '/search?q=test&page=1');

        $this->assertSame('/search', $request->getRequestTarget());
        $this->assertSame(['q' => 'test', 'page' => '1'], $request->getQueryParams());
    }

    public function test_create_explicit_query_overrides_uri_query(): void
    {
        // Explicit $query params take precedence over URI query string
        $request = ServerRequest::create('GET', '/search?q=from_uri', query: ['q' => 'from_param']);

        $this->assertSame(['q' => 'from_param'], $request->getQueryParams());
    }

    public function test_get_uri_returns_uri_interface(): void
    {
        $request = ServerRequest::create();
        $this->assertInstanceOf(\Psr\Http\Message\UriInterface::class, $request->getUri());
    }

    // ─── validate() ────────────────────────────────────────────────────────

    public function test_validate_passes_valid_body(): void
    {
        $request = ServerRequest::create('POST', '/', body: ['name' => 'Ada']);

        $result = $request->validate(['name' => new \Lucent\Validation\Constraints\Required()]);

        $this->assertFalse($result->hasErrors());
    }

    public function test_validate_reports_invalid_body(): void
    {
        $request = ServerRequest::create('POST', '/', body: ['name' => '']);

        $result = $request->validate(['name' => new \Lucent\Validation\Constraints\Required()]);

        $this->assertTrue($result->hasErrors());
        $this->assertArrayHasKey('name', $result->errors());
    }

    public function test_validate_handles_null_body(): void
    {
        $request = ServerRequest::create('GET', '/');

        $result = $request->validate(['name' => new \Lucent\Validation\Constraints\Required()]);

        $this->assertTrue($result->hasErrors());
    }

    public function test_validate_preserves_object_body(): void
    {
        $request = ServerRequest::create('POST', '/', body: ['name' => 'Ada']);
        $request = $request->withParsedBody((object) ['name' => 'Ada']);

        $result = $request->validate(['name' => new \Lucent\Validation\Constraints\Required()]);

        $this->assertFalse($result->hasErrors());
        $this->assertSame('Ada', $result->value('name'));
    }
}