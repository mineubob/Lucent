<?php

namespace Tests\Unit\Message\Stream;

use Lucent\Http\Message\Stream\LazyStream;
use PHPUnit\Framework\TestCase;

class LazyStreamTest extends TestCase
{
    public function test_callback_is_invoked_once_on_read(): void
    {
        $invocationCount = 0;
        $stream = new LazyStream(function () use (&$invocationCount) {
            $invocationCount++;
            return 'Hello, Callback!';
        });

        $this->assertSame('Hello, Callback!', $stream->read(1024));
        $this->assertSame(1, $invocationCount);
    }

    public function test_callback_is_invoked_once_on_get_contents(): void
    {
        $invocationCount = 0;
        $stream = new LazyStream(function () use (&$invocationCount) {
            $invocationCount++;
            return 'Test';
        });

        $this->assertSame('Test', $stream->getContents());
        $this->assertSame(1, $invocationCount);
    }

    public function test_callback_is_invoked_once_on_to_string(): void
    {
        $invocationCount = 0;
        $stream = new LazyStream(function () use (&$invocationCount) {
            $invocationCount++;
            return 'ToString';
        });

        $this->assertSame('ToString', (string) $stream);
        $this->assertSame(1, $invocationCount);
    }

    public function test_eof_after_read(): void
    {
        $stream = new LazyStream(function () {
            return 'Data';
        });

        $this->assertFalse($stream->eof());
        $stream->read(1024);
        $this->assertTrue($stream->eof());
    }

    public function test_get_size_is_null_until_callback_invoked(): void
    {
        // Per PSR-7, getSize() may return null when the size is unknown —
        // invoking the callback eagerly would defeat lazy streaming.
        $stream = new LazyStream(function () {
            return 'Data';
        });

        $this->assertNull($stream->getSize());

        $stream->read(1024);
        $this->assertSame(4, $stream->getSize());
    }

    public function test_is_seekable_returns_false(): void
    {
        $stream = new LazyStream(function () {
            return 'Data';
        });

        $this->assertFalse($stream->isSeekable());
    }

    public function test_is_writable_returns_false(): void
    {
        $stream = new LazyStream(function () {
            return 'Data';
        });

        $this->assertFalse($stream->isWritable());
    }

    public function test_is_readable_returns_true(): void
    {
        $stream = new LazyStream(function () {
            return 'Data';
        });

        $this->assertTrue($stream->isReadable());
    }

    public function test_detach_returns_callable(): void
    {
        $cb = function () {
            return 'Data';
        };
        $stream = new LazyStream($cb);

        $this->assertSame($cb, $stream->detach());
    }

    public function test_close_prevents_further_reads(): void
    {
        $stream = new LazyStream(function () {
            return 'Data';
        });

        $stream->close();
        $this->assertSame('', $stream->read(1024));
    }

    public function test_callback_echo_is_captured(): void
    {
        $stream = new LazyStream(function () {
            echo 'Echoed';
            return '';
        });

        $this->assertSame('Echoed', $stream->getContents());
    }

    public function test_get_metadata_returns_empty_array(): void
    {
        $stream = new LazyStream(function () {
            return 'Data';
        });

        $this->assertSame([], $stream->getMetadata());
    }
}