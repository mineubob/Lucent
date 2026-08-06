<?php

namespace Tests\Unit\Message\Factory;

use Lucent\Http\Message\Factory\HttpFactory;
use Lucent\Http\Message\Request;
use Lucent\Http\Message\Response;
use Lucent\Http\Message\ServerRequest;
use Lucent\Http\Message\Stream;
use Lucent\Http\Message\UploadedFile;
use Lucent\Http\Message\Uri;
use PHPUnit\Framework\TestCase;

class HttpFactoryTest extends TestCase
{
    private HttpFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->factory = new HttpFactory();
    }

    public function test_create_request(): void
    {
        $request = $this->factory->createRequest('GET', 'https://example.com');

        $this->assertInstanceOf(Request::class, $request);
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('https://example.com', (string) $request->getUri());
    }

    public function test_create_request_with_uri_object(): void
    {
        $uri = Uri::fromString('https://example.com/path');
        $request = $this->factory->createRequest('POST', $uri);

        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/path', $request->getRequestTarget());
    }

    public function test_create_response(): void
    {
        $response = $this->factory->createResponse(201, 'Created');

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('Created', $response->getReasonPhrase());
    }

    public function test_create_response_defaults(): void
    {
        $response = $this->factory->createResponse();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('OK', $response->getReasonPhrase());
    }

    public function test_create_server_request(): void
    {
        $request = $this->factory->createServerRequest('PUT', 'https://api.example.com/data');

        $this->assertInstanceOf(ServerRequest::class, $request);
        $this->assertSame('PUT', $request->getMethod());
    }

    public function test_create_stream(): void
    {
        $stream = $this->factory->createStream('Hello, World!');

        $this->assertInstanceOf(Stream::class, $stream);
        $this->assertSame('Hello, World!', (string) $stream);
    }

    public function test_create_stream_from_file(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'psr7_test_');
        file_put_contents($file, 'File content');

        $stream = $this->factory->createStreamFromFile($file);

        $this->assertInstanceOf(Stream::class, $stream);
        $this->assertSame('File content', (string) $stream);

        unlink($file);
    }

    public function test_create_stream_from_resource(): void
    {
        $resource = fopen('php://memory', 'r+');
        fwrite($resource, 'Resource content');
        rewind($resource);

        $stream = $this->factory->createStreamFromResource($resource);

        $this->assertInstanceOf(Stream::class, $stream);
        $this->assertSame('Resource content', (string) $stream);
    }

    public function test_create_uploaded_file(): void
    {
        $stream = Stream::fromString('upload content');
        $file = $this->factory->createUploadedFile($stream, 14, UPLOAD_ERR_OK, 'test.txt', 'text/plain');

        $this->assertInstanceOf(UploadedFile::class, $file);
        $this->assertSame(14, $file->getSize());
        $this->assertSame(UPLOAD_ERR_OK, $file->getError());
        $this->assertSame('test.txt', $file->getClientFilename());
        $this->assertSame('text/plain', $file->getClientMediaType());
    }

    public function test_create_uri(): void
    {
        $uri = $this->factory->createUri('https://example.com:8080/path?q=1');

        $this->assertInstanceOf(Uri::class, $uri);
        $this->assertSame('https', $uri->getScheme());
        $this->assertSame('example.com', $uri->getHost());
        $this->assertSame(8080, $uri->getPort());
    }

    public function test_create_uri_from_uri_object(): void
    {
        $original = Uri::fromString('https://example.com');
        $uri = $this->factory->createUri((string) $original);

        $this->assertInstanceOf(Uri::class, $uri);
        $this->assertSame('https://example.com', (string) $uri);
    }
}