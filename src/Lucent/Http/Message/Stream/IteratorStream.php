<?php
declare(strict_types=1);


namespace Lucent\Http\Message\Stream;

use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Traversable;

/**
 * PSR-7 stream implementation backed by an iterator/generator.
 *
 * read($length) pulls items from the iterator, getContents() iterates to
 * completion. This maps to StreamController::stream(): Generator — each
 * yielded value becomes a chunk of the stream content.
 *
 * Suitable for SSE, large dataset streaming, and any response where content
 * is generated incrementally.
 */
final class IteratorStream implements StreamInterface
{
    /** @var \Iterator|null */
    private ?\Iterator $iterator = null;

    private int $position = 0;
    private bool $exhausted = false;

    public function __construct(Traversable $iterator)
    {
        if ($iterator instanceof \IteratorAggregate) {
            $iterator = $iterator->getIterator();
        }
        $this->iterator = $iterator;
    }

    public function __toString(): string
    {
        try {
            $this->iterator?->rewind();
            $this->position = 0;
            $this->exhausted = false;
            return $this->getContents();
        } catch (\Throwable) {
            return '';
        }
    }

    public function close(): void
    {
        $this->iterator = null;
        $this->position = 0;
        $this->exhausted = false;
    }

    public function detach(): ?\Iterator
    {
        $iterator = $this->iterator;
        $this->iterator = null;
        $this->position = 0;
        $this->exhausted = false;
        return $iterator;
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function tell(): int
    {
        return $this->position;
    }

    public function eof(): bool
    {
        return $this->exhausted || $this->iterator === null;
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        throw new RuntimeException('IteratorStream is not seekable');
    }

    public function rewind(): void
    {
        throw new RuntimeException('IteratorStream is not seekable');
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new RuntimeException('IteratorStream is not writable');
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function read(int $length): string
    {
        if ($this->exhausted || $this->iterator === null) {
            return '';
        }

        $contents = '';
        while ($this->iterator->valid() && strlen($contents) < $length) {
            $chunk = (string) $this->iterator->current();
            $this->iterator->next();
            $this->position += strlen($chunk);
            $contents .= $chunk;

            // True streaming: return after the first yielded chunk so the
            // emitter (Application::executeHttpRequest()) flushes per event
            // instead of batching until $length bytes accumulate. PSR-7
            // permits read() to return fewer bytes than requested.

            if ($contents !== '') {
                break;
            }
        }

        if (! $this->iterator->valid()) {
            $this->exhausted = true;
        }

        return $contents;
    }

    public function getContents(): string
    {
        if ($this->exhausted || $this->iterator === null) {
            return '';
        }

        $contents = '';
        while ($this->iterator->valid()) {
            $chunk = (string) $this->iterator->current();
            $this->iterator->next();
            $this->position += strlen($chunk);
            $contents .= $chunk;
        }

        $this->exhausted = true;
        return $contents;
    }

    public function getMetadata(?string $key = null)
    {
        if ($key === null) {
            return [];
        }
        return null;
    }
}