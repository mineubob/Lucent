<?php

namespace Tests\Unit;

use Lucent\Http\Message\ServerRequest;
use Lucent\Http\RouteInfo;
use PHPUnit\Framework\TestCase;

class HttpRequestTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SERVER = [];
    }

    public function test_bearer_token_extraction()
    {
        // Test valid bearer token
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer abc123token';
        $request = ServerRequest::fromGlobals();
        $this->assertEquals('Bearer abc123token', $request->getHeaderLine('Authorization'));
        $this->assertStringStartsWith('Bearer ', $request->getHeaderLine('Authorization'));

        // Test missing Authorization header
        $_SERVER = [];
        $request = ServerRequest::fromGlobals();
        $this->assertSame('', $request->getHeaderLine('Authorization'));

        // Test malformed Authorization header
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic abc123';
        $request = ServerRequest::fromGlobals();
        $this->assertStringStartsWith('Basic', $request->getHeaderLine('Authorization'));

        // Test empty bearer token
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ';
        $request = ServerRequest::fromGlobals();
        $this->assertSame('Bearer ', $request->getHeaderLine('Authorization'));
    }

    public function test_header_retrieval()
    {
        // Test basic header retrieval
        $_SERVER['HTTP_CONTENT_TYPE'] = 'application/json';
        $_SERVER['HTTP_X_CUSTOM_HEADER'] = 'custom-value';
        $request = ServerRequest::fromGlobals();

        $this->assertEquals('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertEquals('custom-value', $request->getHeaderLine('X-Custom-Header'));

        // Test case insensitivity
        $this->assertEquals('custom-value', $request->getHeaderLine('x-custom-header'));

        // Test default value for missing header
        $this->assertSame('', $request->getHeaderLine('nonexistent'));
    }

    public function test_all_headers_retrieval()
    {
        $_SERVER['HTTP_CONTENT_TYPE'] = 'application/json';
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        $_SERVER['HTTP_X_CUSTOM_HEADER'] = 'custom-value';

        $request = ServerRequest::fromGlobals();
        $headers = $request->getHeaders();

        $this->assertIsArray($headers);
        $this->assertArrayHasKey('Content-Type', $headers);
        $this->assertArrayHasKey('Accept', $headers);
        $this->assertArrayHasKey('X-Custom-Header', $headers);
        $this->assertEquals(['application/json'], $headers['Content-Type']);
    }

    public function test_json_request_detection()
    {
        // Test JSON content type detection
        $_SERVER['CONTENT_TYPE'] = 'application/json';
        $request = ServerRequest::fromGlobals();
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));

        // Test non-JSON content type
        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
        $request = ServerRequest::fromGlobals();
        $this->assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));

        // Test missing content type
        unset($_SERVER['CONTENT_TYPE']);
        $request = ServerRequest::fromGlobals();
        $this->assertSame('', $request->getHeaderLine('Content-Type'));
    }

    public function test_header_normalization()
    {
        $_SERVER['HTTP_CONTENT_TYPE'] = 'application/json';
        $_SERVER['HTTP_X_CUSTOM_HEADER'] = 'value';
        $request = ServerRequest::fromGlobals();

        $headers = $request->getHeaders();

        // Test that HTTP_ prefix is removed and format is correct
        $this->assertArrayHasKey('Content-Type', $headers);
        $this->assertArrayHasKey('X-Custom-Header', $headers);

        // Test that underscores are converted to dashes
        $this->assertArrayNotHasKey('CONTENT_TYPE', $headers);
        $this->assertArrayNotHasKey('X_CUSTOM_HEADER', $headers);
    }

    public function test_header_sanitization()
    {
        $_SERVER['HTTP_X_UNSAFE'] = "test\r\nX-Injected: malicious";
        $request = ServerRequest::fromGlobals();

        $headers = $request->getHeaders();

        // Verify that header injection attempts are prevented
        $this->assertArrayHasKey('X-Unsafe', $headers);
        $this->assertArrayNotHasKey('X-Injected', $headers);
    }

    public function test_method_and_uri()
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/test/123?q=1';

        $request = ServerRequest::fromGlobals();

        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/test/123', $request->getUri()->getPath());
        $this->assertSame('q=1', $request->getUri()->getQuery());
    }

    public function test_route_info_attribute()
    {
        $request = ServerRequest::fromGlobals();

        $request = $request->withAttribute('routeInfo', new RouteInfo(
            'App\\Controllers\\TestController',
            'show',
            '/test/123',
            'GET',
            ['id' => '123']
        ));

        // Assert RouteInfo was set correctly
        $routeInfo = $request->getRouteInfo();
        $this->assertNotNull($routeInfo);
        $this->assertEquals('App\\Controllers\\TestController', $routeInfo->controllerClass);
        $this->assertEquals('show', $routeInfo->method);
    }
}