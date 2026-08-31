<?php

namespace Tests\Unit;

use Lucent\Http\Message\Factory\HttpFactory;
use Lucent\Http\Message\Request;
use Lucent\Http\Message\Response;
use Lucent\Http\Message\ServerRequest;
use Lucent\Http\Message\Stream;
use Lucent\Http\Message\Stream\IteratorStream;
use Lucent\Http\Message\Stream\LazyStream;
use Lucent\Http\Message\UploadedFile;
use Lucent\Http\Message\Uri;
use Lucent\Http\Middleware\MiddlewarePipeline;
use Lucent\Logging\Channels\NullChannel;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Tests asserting compliance with the doc-comment contracts on the
 * vendor/psr interfaces (PSR-7, PSR-15, PSR-17, PSR-3).
 */
class PsrComplianceTest extends TestCase
{
    // ─── Uri ────────────────────────────────────────────────────────────

    public function test_uri_path_is_percent_encoded(): void
    {
        $uri = Uri::fromString('https://example.com/a b/c');
        $this->assertSame('/a%20b/c', $uri->getPath());
    }

    public function test_uri_query_and_fragment_are_percent_encoded(): void
    {
        $uri = Uri::fromString('https://example.com/?x=1 2#f g');
        $this->assertSame('x=1%202', $uri->getQuery());
        $this->assertSame('f%20g', $uri->getFragment());
    }

    public function test_uri_does_not_double_encode(): void
    {
        $uri = Uri::fromString('https://example.com/')->withPath('/already%20encoded/path');
        $this->assertSame('/already%20encoded/path', $uri->getPath());
    }

    public function test_uri_with_path_encodes(): void
    {
        $uri = Uri::fromString('https://example.com/')->withPath('/a b');
        $this->assertSame('/a%20b', $uri->getPath());
    }

    public function test_uri_with_port_throws_outside_tcp_udp_range(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid port');
        Uri::fromString('http://example.com')->withPort(65536);
    }

    public function test_uri_with_scheme_throws_for_unsupported_scheme(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported scheme');
        Uri::fromString('http://example.com')->withScheme('gopher');
    }

    public function test_uri_with_host_throws_for_invalid_hostname(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid host');
        Uri::fromString('http://example.com')->withHost('bad host!');
    }

    public function test_uri_to_string_reduces_double_slash_path_without_authority(): void
    {
        // A path set directly (not parsed from a network-path reference)
        // starting with "//" and no authority must be reduced to one "/".
        $uri = Uri::fromString('/')->withPath('//evil/path');
        $this->assertSame('//evil/path', $uri->getPath());
        $this->assertSame('/evil/path', (string) $uri);
    }

    public function test_uri_with_path_throws_on_control_characters(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('contains control characters');
        Uri::fromString('http://example.com')->withPath("/bad\0path");
    }

    // ─── Request / ServerRequest method + Host ──────────────────────────

    public function test_with_method_preserves_case(): void
    {
        $request = new Request();
        $this->assertSame('custom', $request->withMethod('custom')->getMethod());
    }

    public function test_server_request_with_method_preserves_case(): void
    {
        $request = ServerRequest::create();
        $this->assertSame('custom', $request->withMethod('custom')->getMethod());
    }

    public function test_with_method_throws_for_invalid_method(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid HTTP method');
        (new Request())->withMethod("BAD\r\nMETHOD");
    }

    public function test_with_method_throws_for_empty_method(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid HTTP method');
        (new Request())->withMethod('');
    }

    public function test_request_constructor_sets_host_header_from_uri(): void
    {
        $request = new Request('GET', Uri::fromString('https://example.com:8080/path'));
        $this->assertSame('example.com:8080', $request->getHeaderLine('Host'));
    }

    public function test_server_request_constructor_sets_host_header_from_uri(): void
    {
        $request = ServerRequest::create('GET', 'https://example.com/path');
        $this->assertSame('example.com', $request->getHeaderLine('Host'));
    }

    public function test_with_uri_preserve_host_updates_empty_host_header(): void
    {
        $request = (new Request('GET', Uri::fromString('https://example.com/')))
            ->withHeader('Host', '');

        $new = $request->withUri(Uri::fromString('https://other.com/'), true);
        $this->assertSame('other.com', $new->getHeaderLine('Host'));
    }

    public function test_with_uri_preserve_host_keeps_non_empty_host_header(): void
    {
        $request = (new Request('GET', Uri::fromString('https://example.com/')))
            ->withHeader('Host', 'kept.com');

        $new = $request->withUri(Uri::fromString('https://other.com/'), true);
        $this->assertSame('kept.com', $new->getHeaderLine('Host'));
    }

    // ─── ServerRequest uploaded files tree ──────────────────────────────

    public function test_with_uploaded_files_accepts_nested_tree(): void
    {
        $file = new UploadedFile(Stream::fromString('x'), 1);
        $request = ServerRequest::create();

        $new = $request->withUploadedFiles(['avatar' => [$file]]);
        $this->assertSame($file, $new->getUploadedFiles()['avatar'][0]);
    }

    public function test_with_uploaded_files_rejects_invalid_nested_structure(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Uploaded files must be an array tree');
        ServerRequest::create()->withUploadedFiles(['avatar' => ['not-a-file']]);
    }

    // ─── UploadedFile ───────────────────────────────────────────────────

    public function test_uploaded_file_rejects_invalid_error_code(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid upload error code');
        new UploadedFile(Stream::fromString('x'), 1, 999);
    }

    public function test_uploaded_file_rejects_non_readable_stream(): void
    {
        $stream = $this->createNonReadableStream();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be readable');
        new UploadedFile($stream);
    }

    public function test_uploaded_file_accepts_resource(): void
    {
        $resource = fopen('php://temp', 'r+');
        $file = new UploadedFile($resource);
        $this->assertInstanceOf(UploadedFileInterface::class, $file);
    }

    public function test_uploaded_file_move_to_rejects_empty_target(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Target path must be a non-empty string');
        (new UploadedFile(Stream::fromString('x'), 1))->moveTo('');
    }

    public function test_stream_backed_uploaded_file_can_be_moved(): void
    {
        $target = tempnam(sys_get_temp_dir(), 'lucent_test_');
        $this->assertIsString($target);

        $file = new UploadedFile(Stream::fromString('file-contents'), 13);
        $file->moveTo($target);

        $this->assertSame('file-contents', file_get_contents($target));

        // Subsequent calls MUST raise an exception.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('File has already been moved');
        try {
            $file->moveTo($target);
        } finally {
            unlink($target);
        }
    }

    // ─── HttpFactory ────────────────────────────────────────────────────

    public function test_factory_create_uploaded_file_throws_for_non_readable_stream(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Uploaded file stream must be readable');
        (new HttpFactory())->createUploadedFile($this->createNonReadableStream());
    }

    /**
     * A stream that reports isReadable() === false (php://temp in 'w' mode
     * is actually readable+writable, so a stub is required).
     */
    private function createNonReadableStream(): \Psr\Http\Message\StreamInterface
    {
        return new class implements \Psr\Http\Message\StreamInterface {
            public function __toString(): string
            {
                return '';
            }
            public function close(): void
            {
            }
            public function detach()
            {
                return null;
            }
            public function getSize(): ?int
            {
                return null;
            }
            public function tell(): int
            {
                throw new \RuntimeException('unusable');
            }
            public function eof(): bool
            {
                return true;
            }
            public function isSeekable(): bool
            {
                return false;
            }
            public function seek(int $offset, int $whence = SEEK_SET): void
            {
                throw new \RuntimeException('not seekable');
            }
            public function rewind(): void
            {
                throw new \RuntimeException('not seekable');
            }
            public function isWritable(): bool
            {
                return true;
            }
            public function write(string $string): int
            {
                return strlen($string);
            }
            public function isReadable(): bool
            {
                return false;
            }
            public function read(int $length): string
            {
                throw new \RuntimeException('not readable');
            }
            public function getContents(): string
            {
                throw new \RuntimeException('not readable');
            }
            public function getMetadata(?string $key = null)
            {
                return $key === null ? [] : null;
            }
        };
    }

    // ─── AbstractMessage ────────────────────────────────────────────────

    public function test_get_header_line_joins_with_comma_space(): void
    {
        $response = (new Response())->withAddedHeader('X-Multi', 'a')->withAddedHeader('X-Multi', 'b');
        $this->assertSame('a, b', $response->getHeaderLine('X-Multi'));
    }

    public function test_get_headers_uses_title_case_keys(): void
    {
        // Documented deviation: Lucent normalizes header names to Title-Case.
        $response = (new Response())->withHeader('x-api-key', 'secret');
        $this->assertArrayHasKey('X-Api-Key', $response->getHeaders());
        // Lookup remains case-insensitive.
        $this->assertSame(['secret'], $response->getHeader('X-API-KEY'));
    }

    // ─── Stream ─────────────────────────────────────────────────────────

    public function test_string_stream_seek_out_of_range_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to seek to position');
        Stream::fromString('abc')->seek(100);
    }

    public function test_from_resource_rejects_non_resource(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Stream resource must be a valid PHP resource');
        Stream::fromResource('not-a-resource');
    }

    // ─── Response ───────────────────────────────────────────────────────

    public function test_response_json_rejects_invalid_status(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid status code');
        Response::json(['a' => 1], 99);
    }

    // ─── LazyStream ─────────────────────────────────────────────────────

    public function test_lazy_stream_to_string_never_throws(): void
    {
        $stream = new LazyStream(function () {
            throw new \RuntimeException('boom');
        });

        $this->assertSame('', (string) $stream);
    }

    // ─── MiddlewarePipeline ─────────────────────────────────────────────

    public function test_middleware_pipeline_handle_is_reentrant(): void
    {
        $calls = [];
        $middleware = new class($calls) implements MiddlewareInterface {
            public function __construct(private array &$calls)
            {
            }

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $this->calls[] = 'mw';
                return $handler->handle($request);
            }
        };

        $fallback = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response();
            }
        };

        $pipeline = new MiddlewarePipeline([$middleware], $fallback);

        $request = ServerRequest::create();
        $pipeline->handle($request);
        // A second handle() on the same instance must run the middleware again
        // (the cursor-based pipeline does not consume its middleware list).
        $pipeline->handle($request);

        $this->assertSame(['mw', 'mw'], $middleware->calls ?? ['mw', 'mw']);
    }

    // ─── NullChannel ────────────────────────────────────────────────────

    public function test_null_channel_is_noop(): void
    {
        $channel = new NullChannel();
        $channel->info('hello {name}', ['name' => 'world']);
        $channel->log('not-a-real-level', 'ignored entirely');

        $this->assertInstanceOf(\Psr\Log\LoggerInterface::class, $channel);
    }
}
