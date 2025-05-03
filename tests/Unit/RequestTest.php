<?php

namespace Unit;

use Lucent\Http\Request;
use Lucent\Http\RouteInfo;
use PHPUnit\Framework\TestCase;

class RequestTest extends TestCase
{
    private Request $request;

    protected function setUp(): void
    {
        parent::setUp();
        $this->request = new Request();

        // Reset $_SERVER global for each test
        $_SERVER = [];
    }

    public function test_bearer_token_extraction()
    {
        // Test valid bearer token
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer abc123token';
        $request = new Request();
        $this->assertEquals('abc123token', $request->bearerToken());

        // Test missing Authorization header
        $_SERVER = [];
        $request = new Request();
        $this->assertNull($request->bearerToken());

        // Test malformed Authorization header
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic abc123';
        $request = new Request();
        $this->assertNull($request->bearerToken());

        // Test empty bearer token
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ';
        $request = new Request();
        $this->assertNull($request->bearerToken());
    }

    public function test_header_retrieval()
    {
        // Test basic header retrieval
        $_SERVER['HTTP_CONTENT_TYPE'] = 'application/json';
        $_SERVER['HTTP_X_CUSTOM_HEADER'] = 'custom-value';
        $request = new Request();

        $this->assertEquals('application/json', $request->header('Content-Type'));
        $this->assertEquals('custom-value', $request->header('X-Custom-Header'));

        // Test case insensitivity
        $this->assertEquals('custom-value', $request->header('x-custom-header'));

        // Test default value for missing header
        $this->assertEquals('default', $request->header('nonexistent', 'default'));
        $this->assertNull($request->header('nonexistent'));
    }

    public function test_all_headers_retrieval()
    {
        $_SERVER['HTTP_CONTENT_TYPE'] = 'application/json';
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        $_SERVER['HTTP_X_CUSTOM_HEADER'] = 'custom-value';

        $request = new Request();
        $headers = $request->headers();

        $this->assertIsArray($headers);
        $this->assertArrayHasKey('Content-Type', $headers);
        $this->assertArrayHasKey('Accept', $headers);
        $this->assertArrayHasKey('X-Custom-Header', $headers);
        $this->assertEquals('application/json', $headers['Content-Type']);
    }

    public function test_json_request_detection()
    {
        // Test JSON content type detection
        $_SERVER['CONTENT_TYPE'] = 'application/json';
        $request = new Request();
        $this->assertTrue($this->invokeMethod($request, 'isJsonRequest'));

        // Test non-JSON content type
        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
        $request = new Request();
        $this->assertFalse($this->invokeMethod($request, 'isJsonRequest'));

        // Test missing content type
        unset($_SERVER['CONTENT_TYPE']);
        $request = new Request();
        $this->assertFalse($this->invokeMethod($request, 'isJsonRequest'));
    }

    public function test_header_normalization()
    {
        $_SERVER['HTTP_CONTENT_TYPE'] = 'application/json';
        $_SERVER['HTTP_X_CUSTOM_HEADER'] = 'value';
        $request = new Request();

        $headers = $this->invokeMethod($request, 'getHeaders');

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
        $request = new Request();

        $headers = $request->headers();

        // Verify that header injection attempts are prevented
        $this->assertArrayHasKey('X-Unsafe', $headers);
        $this->assertEquals("test\r\nX-Injected: malicious", $headers['X-Unsafe']);
        $this->assertArrayNotHasKey('X-Injected', $headers);
    }

    public function test_header_set(): void
    {
        $_SERVER['HTTP_CONTENT_TYPE'] = 'text/html';

        $request = new Request();

        $this->assertEquals('text/html', $request->header('Content-Type'));

        $request->setHeader('Content-Type', 'application/json');
        $this->assertEquals('application/json', $request->header('Content-Type'));

    }

    public function test_route_info() : void
    {
        $request = new Request();

        $routeResponse = [
            "outcome" => true,
            "controller" => "App\\Controllers\\TestController",
            "method" => "show",
            "route" => "/test/123",
            "variables" => ["id" => "123"],
            "middleware" => []
        ];

        $routeInfo = new RouteInfo(
            $routeResponse["controller"],
            $routeResponse["method"],
            $routeResponse["route"],
            'GET',
            $routeResponse["variables"]
        );

        // Set the RouteInfo on the Request
        $request->setRouteInfo($routeInfo);

        // Assert RouteInfo was set correctly
        $this->assertNotNull($request->routeInfo);
        $this->assertEquals("App\\Controllers\\TestController", $request->routeInfo->controllerClass);
        $this->assertEquals("show", $request->routeInfo->method);
    }

    /**
     * Helper method to invoke private methods for testing
     */
    private function invokeMethod($object, string $methodName, array $parameters = [])
    {
        $reflection = new \ReflectionClass(get_class($object));
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);
        return $method->invokeArgs($object, $parameters);
    }
}