<?php

namespace Unit\Message;

use Lucent\Http\Message\Stream;
use PHPUnit\Framework\TestCase;

class StreamTest extends TestCase
{
    public function test_from_string_creates_readable_stream(): void
    {
        $stream = Stream::fromString('Hello, World!');
        $this->assertSame('Hello, World!', (string) $stream);
        $this->assertTrue($stream->isReadable());
        $this->assertFalse($stream->isWritable());
        $this->assertTrue($stream->isSeekable());
    }

    public function test_get_contents_returns_full_content(): void
    {
        $stream = Stream::fromString('Test content');
        $this->assertSame('Test content', $stream->getContents());
    }

    public function test_read_returns_partial_content(): void
    {
        $stream = Stream::fromString('Hello, World!');
        $this->assertSame('Hello', $stream->read(5));
        $this->assertSame(', Wor', $stream->read(5));
    }

    public function test_tell_returns_current_position(): void
    {
        $stream = Stream::fromString('Hello');
        $this->assertSame(0, $stream->tell());
        $stream->read(2);
        $this->assertSame(2, $stream->tell());
    }

    public function test_seek_changes_position(): void
    {
        $stream = Stream::fromString('Hello, World!');
        $stream->seek(7);
        $this->assertSame('World!', $stream->getContents());
    }

    public function test_rewind_resets_position(): void
    {
        $stream = Stream::fromString('Hello, World!');
        $stream->read(5);
        $stream->rewind();
        $this->assertSame(0, $stream->tell());
        $this->assertSame('Hello, World!', $stream->getContents());
    }

    public function test_eof_detects_end(): void
    {
        $stream = Stream::fromString('Hi');
        $this->assertFalse($stream->eof());
        $stream->read(2);
        $this->assertTrue($stream->eof());
    }

    public function test_get_size_returns_length(): void
    {
        $stream = Stream::fromString('Hello');
        $this->assertSame(5, $stream->getSize());
    }

    public function test_write_throws_for_string_backed_stream(): void
    {
        $this->expectException(\RuntimeException::class);
        $stream = Stream::fromString('Hello');
        $stream->write('test');
    }

    public function test_close_detaches_stream(): void
    {
        $stream = Stream::fromString('Test');
        $stream->close();
        $this->assertNull($stream->getSize());
        $this->assertFalse($stream->isReadable());
        $this->assertFalse($stream->isWritable());
        $this->assertFalse($stream->isSeekable());
    }

    public function test_detach_returns_null_for_string_backed(): void
    {
        $stream = Stream::fromString('Test');
        $this->assertNull($stream->detach());
    }

    public function test_get_metadata_returns_all_metadata(): void
    {
        $stream = Stream::fromString('Test');
        $metadata = $stream->getMetadata();
        $this->assertIsArray($metadata);
    }

    public function test_get_metadata_with_key_returns_null_for_string_backed(): void
    {
        $stream = Stream::fromString('Test');
        $this->assertNull($stream->getMetadata('stream_type'));
    }

    public function test_get_metadata_with_invalid_key_returns_null(): void
    {
        $stream = Stream::fromString('Test');
        $this->assertNull($stream->getMetadata('nonexistent'));
    }

    public function test_from_resource_creates_stream(): void
    {
        $resource = fopen('php://memory', 'r+');
        fwrite($resource, 'From resource');
        rewind($resource);

        $stream = Stream::fromResource($resource);
        $this->assertSame('From resource', (string) $stream);
    }

    public function test_empty_string_returns_empty_stream(): void
    {
        $stream = Stream::fromString('');
        $this->assertSame('', (string) $stream);
        $this->assertTrue($stream->eof());
    }

    public function test_to_string_rewinds_before_reading(): void
    {
        $stream = Stream::fromString('Test');
        $stream->read(2);
        $this->assertSame('Test', (string) $stream);
    }

    public function test_is_readable_mode(): void
    {
        $this->assertTrue(Stream::fromString('test')->isReadable());
    }

    public function test_is_writable_mode(): void
    {
        $this->assertFalse(Stream::fromString('test')->isWritable());
    }
}