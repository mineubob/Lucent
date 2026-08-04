<?php

namespace Lucent\Http\Message;

use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * PSR-7 stream implementation wrapping a string or PHP resource.
 *
 * Supports string-backed streams (in-memory) and resource-backed streams
 * (file handles, php://memory, php://temp, etc.).
 */
final class Stream implements StreamInterface
{
    /** @var resource|null Underlying PHP stream resource */
    private $stream = null;

    /** @var string|null In-memory content for string-backed streams */
    private ?string $content = null;

    /** @var int Position in in-memory content */
    private int $position = 0;

    /** @var bool Whether the stream is readable */
    private bool $readable;

    /** @var bool Whether the stream is writable */
    private bool $writable;

    /** @var bool Whether the stream is seekable */
    private bool $seekable;

    /** @var int|null Cached size */
    private ?int $size = null;

    /**
     * Private constructor — use fromString() or fromResource() instead.
     */
    private function __construct()
    {
        $this->readable = false;
        $this->writable = false;
        $this->seekable = false;
    }

    /**
     * Create a stream from a string.
     *
     * @param string $content The string content to wrap
     * @return self
     */
    public static function fromString(string $content): self
    {
        $stream = new self();
        $stream->content = $content;
        $stream->position = 0;
        $stream->readable = true;
        $stream->seekable = true;
        $stream->size = strlen($content);
        return $stream;
    }

    /**
     * Create a stream from a PHP resource.
     *
     * @param resource $resource A PHP stream resource
     * @return self
     */
    public static function fromResource($resource): self
    {
        $stream = new self();
        $stream->stream = $resource;
        $meta = stream_get_meta_data($resource);
        $mode = $meta['mode'] ?? '';
        $stream->seekable = $meta['seekable'] ?? false;
        $stream->readable = self::isReadableMode($mode);
        $stream->writable = self::isWritableMode($mode);
        return $stream;
    }

    /**
     * Determine if a stream mode is readable.
     *
     * @see https://www.php.net/manual/en/function.fopen.php
     */
    private static function isReadableMode(string $mode): bool
    {
        return str_starts_with($mode, 'r') || str_contains($mode, '+');
    }

    /**
     * Determine if a stream mode is writable.
     *
     * @see https://www.php.net/manual/en/function.fopen.php
     */
    private static function isWritableMode(string $mode): bool
    {
        return str_starts_with($mode, 'a')
            || str_starts_with($mode, 'w')
            || str_starts_with($mode, 'x')
            || str_starts_with($mode, 'c')
            || str_contains($mode, '+');
    }

    public function __toString(): string
    {
        if ($this->stream !== null) {
            try {
                if ($this->isSeekable()) {
                    $this->seek(0);
                }
                return $this->getContents();
            } catch (\Throwable) {
                return '';
            }
        }

        return $this->content ?? '';
    }

    public function close(): void
    {
        if ($this->stream !== null) {
            if (is_resource($this->stream)) {
                fclose($this->stream);
            }
            $this->detach();
            return;
        }

        $this->content = null;
        $this->position = 0;
        $this->size = null;
        $this->readable = false;
        $this->writable = false;
        $this->seekable = false;
    }

    /**
     * Closes the stream on destruction.
     *
     * @return void
     */
    public function __destruct()
    {
        $this->close();
    }

    public function detach()
    {
        $resource = $this->stream;
        $this->stream = null;
        $this->content = null;
        $this->position = 0;
        $this->size = null;
        $this->readable = false;
        $this->writable = false;
        $this->seekable = false;
        return $resource;
    }

    public function getSize(): ?int
    {
        if ($this->size !== null) {
            return $this->size;
        }

        if ($this->stream !== null) {
            $stats = fstat($this->stream);
            if ($stats !== false) {
                $this->size = $stats['size'];
                return $this->size;
            }
        }

        if ($this->content !== null) {
            $this->size = strlen($this->content);
            return $this->size;
        }

        return null;
    }

    public function tell(): int
    {
        if ($this->stream !== null) {
            $position = ftell($this->stream);
            if ($position === false) {
                throw new RuntimeException('Unable to determine stream position');
            }
            return $position;
        }

        if ($this->content !== null) {
            return $this->position;
        }

        throw new RuntimeException('Stream is closed or unusable');
    }

    public function eof(): bool
    {
        if ($this->stream !== null) {
            return feof($this->stream);
        }

        if ($this->content !== null) {
            return $this->position >= strlen($this->content);
        }

        return true;
    }

    public function isSeekable(): bool
    {
        return $this->seekable;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        if ($this->stream !== null) {
            if (! $this->seekable) {
                throw new RuntimeException('Stream is not seekable');
            }
            if (fseek($this->stream, $offset, $whence) !== 0) {
                throw new RuntimeException('Unable to seek to position');
            }
            return;
        }

        if ($this->content !== null) {
            if (! $this->seekable) {
                throw new RuntimeException('Stream is not seekable');
            }
            $length = strlen($this->content);
            switch ($whence) {
                case SEEK_SET:
                    $this->position = $offset;
                    break;
                case SEEK_CUR:
                    $this->position += $offset;
                    break;
                case SEEK_END:
                    $this->position = $length + $offset;
                    break;
            }
            $this->position = max(0, min($this->position, $length));
            return;
        }

        throw new RuntimeException('Stream is closed or unusable');
    }

    public function rewind(): void
    {
        $this->seek(0);
    }

    public function isWritable(): bool
    {
        return $this->writable;
    }

    public function write(string $string): int
    {
        if ($this->stream !== null) {
            if (! $this->writable) {
                throw new RuntimeException('Stream is not writable');
            }
            $bytes = fwrite($this->stream, $string);
            if ($bytes === false) {
                throw new RuntimeException('Unable to write to stream');
            }
            $this->size = null;
            return $bytes;
        }

        if ($this->content !== null) {
            throw new RuntimeException('String-backed streams are not writable');
        }

        throw new RuntimeException('Stream is closed or unusable');
    }

    public function isReadable(): bool
    {
        return $this->readable;
    }

    public function read(int $length): string
    {
        if ($this->stream !== null) {
            if (! $this->readable) {
                throw new RuntimeException('Stream is not readable');
            }
            $data = fread($this->stream, $length);
            if ($data === false) {
                throw new RuntimeException('Unable to read from stream');
            }
            return $data;
        }

        if ($this->content !== null) {
            if (! $this->readable) {
                throw new RuntimeException('Stream is not readable');
            }
            $data = substr($this->content, $this->position, $length);
            $this->position += strlen($data);
            return $data;
        }

        throw new RuntimeException('Stream is closed or unusable');
    }

    public function getContents(): string
    {
        if ($this->stream !== null) {
            if (! $this->readable) {
                throw new RuntimeException('Stream is not readable');
            }
            $contents = stream_get_contents($this->stream);
            if ($contents === false) {
                throw new RuntimeException('Unable to read stream contents');
            }
            return $contents;
        }

        if ($this->content !== null) {
            $data = substr($this->content, $this->position);
            $this->position = strlen($this->content);
            return $data;
        }

        throw new RuntimeException('Stream is closed or unusable');
    }

    public function getMetadata(?string $key = null)
    {
        if ($this->stream !== null) {
            $meta = stream_get_meta_data($this->stream);
            if ($key === null) {
                return $meta;
            }
            return $meta[$key] ?? null;
        }

        if ($key === null) {
            return [];
        }

        return null;
    }
}
