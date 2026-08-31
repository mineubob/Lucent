<?php

namespace Tests\Unit\Message\Stream;

use Lucent\Http\Message\Stream\IteratorStream;
use PHPUnit\Framework\TestCase;

class IteratorStreamTest extends TestCase
{
    public function test_read_returns_one_chunk_per_call(): void
    {
        $stream = new IteratorStream($this->generateChunks(['Chunk1', 'Chunk2', 'Chunk3']));

        // Per-chunk streaming: read() returns after the first yielded chunk,
        // even when more bytes were requested.

        $this->assertSame('Chunk1', $stream->read(1024));
        $this->assertFalse($stream->eof());
        $this->assertSame('Chunk2', $stream->read(1024));
        $this->assertSame('Chunk3', $stream->read(1024));
        $this->assertTrue($stream->eof());
        $this->assertSame('', $stream->read(1024));
    }

    public function test_get_contents_returns_all_chunks(): void
    {
        $stream = new IteratorStream($this->generateChunks(['Hello', ' ', 'World']));

        $this->assertSame('Hello World', $stream->getContents());
    }

    public function test_to_string_returns_all_chunks(): void
    {
        $stream = new IteratorStream($this->generateChunks(['A', 'B', 'C']));

        $this->assertSame('ABC', (string) $stream);
    }

    public function test_eof_before_read(): void
    {
        $stream = new IteratorStream($this->generateChunks(['Data']));

        $this->assertFalse($stream->eof());
    }

    public function test_eof_after_exhaustion(): void
    {
        $stream = new IteratorStream($this->generateChunks(['Only']));
        $stream->read(1024);

        $this->assertTrue($stream->eof());
    }

    public function test_get_size_returns_null(): void
    {
        $stream = new IteratorStream($this->generateChunks(['Data']));

        $this->assertNull($stream->getSize());
    }

    public function test_is_seekable_returns_false(): void
    {
        $stream = new IteratorStream($this->generateChunks(['Data']));

        $this->assertFalse($stream->isSeekable());
    }

    public function test_is_writable_returns_false(): void
    {
        $stream = new IteratorStream($this->generateChunks(['Data']));

        $this->assertFalse($stream->isWritable());
    }

    public function test_is_readable_returns_true(): void
    {
        $stream = new IteratorStream($this->generateChunks(['Data']));

        $this->assertTrue($stream->isReadable());
    }

    public function test_detach_returns_generator(): void
    {
        $gen = $this->generateChunks(['Data']);
        $stream = new IteratorStream($gen);

        $this->assertSame($gen, $stream->detach());
    }

    public function test_close_prevents_further_reads(): void
    {
        $stream = new IteratorStream($this->generateChunks(['Data']));
        $stream->close();

        $this->assertSame('', $stream->read(1024));
    }

    public function test_eof_returns_true_after_close(): void
    {
        $stream = new IteratorStream($this->generateChunks(['Data']));

        $this->assertFalse($stream->eof());
        $stream->close();

        // eof() must agree with read() (which returns '' after close) so
        // an emitter loop (while !eof()) can't spin on a closed stream.

        $this->assertTrue($stream->eof());
    }

    public function test_empty_generator(): void
    {
        $stream = new IteratorStream($this->generateChunks([]));

        $this->assertFalse($stream->eof());
        $this->assertSame('', $stream->getContents());
        $this->assertTrue($stream->eof());
    }

    public function test_get_metadata_returns_empty_array(): void
    {
        $stream = new IteratorStream($this->generateChunks(['Data']));

        $this->assertSame([], $stream->getMetadata());
    }

    public function test_read_with_length_returns_all(): void
    {
        $stream = new IteratorStream($this->generateChunks(['Hello World']));

        $this->assertSame('Hello World', $stream->read(5));
        $this->assertTrue($stream->eof());
    }

    public function test_tell_tracks_position(): void
    {
        $stream = new IteratorStream($this->generateChunks(['Hello', ' World']));

        $this->assertSame(0, $stream->tell());
        $stream->read(1024);
        $this->assertSame(5, $stream->tell());
        $stream->read(1024);
        $this->assertSame(11, $stream->tell());
    }

    public function test_to_string_handles_exception(): void
    {
        $stream = new IteratorStream((function () {
            yield 'Before';
            throw new \RuntimeException('Stream error');
        })());

        // __toString catches \Throwable and returns empty string
        $result = (string) $stream;
        $this->assertSame('', $result);
    }

    /**
     * Helper to create a generator from an array.
     */
    private function generateChunks(array $chunks): \Generator
    {
        foreach ($chunks as $chunk) {
            yield $chunk;
        }
    }
}