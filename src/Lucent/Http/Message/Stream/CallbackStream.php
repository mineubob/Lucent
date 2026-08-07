<?php

namespace Lucent\Http\Message\Stream;

use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * PSR-7 stream implementation backed by a callable.
 *
 * The callback is invoked once on the first read/getContents/__toString call.
 * Subsequent calls return an empty string. The callback can use echo/printf
 * (captured via output buffering) or return a string.
 *
 * Replaces the old EventStreamResponse's callback mechanism with a PSR-7-compliant stream.
 */
final class CallbackStream implements StreamInterface
{
    /** @var callable|null */
    private $callback;

    /** @var string|null Cached output after first invocation */
    private ?string $output = null;

    private bool $called = false;
    private int $position = 0;

    public function __construct(callable $callback)
    {
        $this->callback = $callback;
    }

    public function __toString(): string
    {
        // String casting must never raise an exception.
        try {
            return $this->getOutput();
        } catch (\Throwable) {
            return '';
        }
    }

    public function close(): void
    {
        $this->callback = null;
        $this->output = null;
        $this->called = false;
        $this->position = 0;
    }

    public function detach(): ?callable
    {
        $callback = $this->callback;
        $this->callback = null;
        $this->output = null;
        $this->called = false;
        $this->position = 0;
        return $callback;
    }

    public function getSize(): ?int
    {
        // Size is unknown until the callback has been invoked — invoking it
        // here would defeat lazy streaming (callers such as CurlHandler call
        // getSize() before sending). Null means "unknown".
        if (!$this->called) {
            return null;
        }

        return strlen($this->output ?? '');
    }

    public function tell(): int
    {
        return $this->position;
    }

    public function eof(): bool
    {
        return $this->position >= strlen($this->getOutput());
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        throw new RuntimeException('CallbackStream is not seekable');
    }

    public function rewind(): void
    {
        throw new RuntimeException('CallbackStream is not seekable');
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new RuntimeException('CallbackStream is not writable');
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function read(int $length): string
    {
        $output = $this->getOutput();
        $data = substr($output, $this->position, $length);
        $this->position += strlen($data);
        return $data;
    }

    public function getContents(): string
    {
        $output = $this->getOutput();
        $data = substr($output, $this->position);
        $this->position = strlen($output);
        return $data;
    }

    public function getMetadata(?string $key = null)
    {
        if ($key === null) {
            return [];
        }
        return null;
    }

    /**
     * Invoke the callback and cache the output.
     *
     * Only invokes the callback once; subsequent calls return the cached output.
     *
     * @return string The cached output
     */
    private function getOutput(): string
    {
        if ($this->called) {
            return $this->output ?? '';
        }

        $this->called = true;

        if ($this->callback === null) {
            return '';
        }

        $callback = $this->callback;

        ob_start();
        try {
            $result = $callback();
        } catch (\Throwable $e) {
            // Close the buffer so callers are not left with a dangling
            // output buffer, then rethrow.
            ob_end_clean();
            throw $e;
        }
        $buffered = ob_get_clean();

        $this->output = ($buffered !== false && $buffered !== '')
            ? $buffered
            : ($result ?? '');

        $this->callback = null;
        return $this->output;
    }
}