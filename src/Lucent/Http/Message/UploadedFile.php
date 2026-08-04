<?php

namespace Lucent\Http\Message;

use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;

/**
 * PSR-7 UploadedFile implementation.
 *
 * Wraps an entry from $_FILES or a stream/resource.
 */
final class UploadedFile implements UploadedFileInterface
{
    private ?StreamInterface $stream = null;
    private ?string $file = null;
    private ?int $size = null;
    private int $error;
    private ?string $clientFilename = null;
    private ?string $clientMediaType = null;
    private bool $moved = false;

    /**
     * @param StreamInterface|string|resource $streamOrFile Stream, file path, or resource
     * @param int|null $size File size in bytes
     * @param int $error Upload error code (UPLOAD_ERR_*)
     * @param string|null $clientFilename Original client filename
     * @param string|null $clientMediaType Original client media type
     */
    public function __construct(
        StreamInterface|string $streamOrFile,
        ?int $size = null,
        int $error = UPLOAD_ERR_OK,
        ?string $clientFilename = null,
        ?string $clientMediaType = null
    ) {
        if ($streamOrFile instanceof StreamInterface) {
            $this->stream = $streamOrFile;
        } else {
            $this->file = $streamOrFile;
        }
        $this->size = $size;
        $this->error = $error;
        $this->clientFilename = $clientFilename;
        $this->clientMediaType = $clientMediaType;
    }

    public function getStream(): StreamInterface
    {
        if ($this->moved) {
            throw new RuntimeException('Cannot get stream after the file has been moved');
        }

        if ($this->stream === null) {
            if ($this->error !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Cannot retrieve stream due to upload error');
            }

            $resource = fopen($this->file, 'r');
            if ($resource === false) {
                throw new RuntimeException('Unable to open uploaded file');
            }

            $this->stream = Stream::fromResource($resource);
        }

        return $this->stream;
    }

    public function moveTo(string $targetPath): void
    {
        if ($this->moved) {
            throw new RuntimeException('File has already been moved');
        }

        if ($this->error !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Cannot move file due to upload error');
        }

        $source = $this->file;
        if ($source === null) {
            throw new RuntimeException('No source file available');
        }

        $dir = dirname($targetPath);
        if (! is_dir($dir)) {
            throw new RuntimeException("Target directory does not exist: $dir");
        }

        if (is_uploaded_file($source)) {
            if (! move_uploaded_file($source, $targetPath)) {
                throw new RuntimeException("Unable to move uploaded file to $targetPath");
            }
        } else {
            // Not an uploaded file (e.g., testing), use rename
            if (! rename($source, $targetPath)) {
                throw new RuntimeException("Unable to rename file to $targetPath");
            }
        }

        $this->moved = true;
        $this->file = $targetPath;
    }

    public function getSize(): ?int
    {
        return $this->size;
    }

    public function getError(): int
    {
        return $this->error;
    }

    public function getClientFilename(): ?string
    {
        return $this->clientFilename;
    }

    public function getClientMediaType(): ?string
    {
        return $this->clientMediaType;
    }
}