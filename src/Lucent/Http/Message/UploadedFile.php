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

    /** @var int[] Valid UPLOAD_ERR_* constants */
    private const VALID_ERRORS = [
        UPLOAD_ERR_OK,
        UPLOAD_ERR_INI_SIZE,
        UPLOAD_ERR_FORM_SIZE,
        UPLOAD_ERR_PARTIAL,
        UPLOAD_ERR_NO_FILE,
        UPLOAD_ERR_NO_TMP_DIR,
        UPLOAD_ERR_CANT_WRITE,
        UPLOAD_ERR_EXTENSION,
    ];

    /**
     * @param StreamInterface|string|resource $streamOrFile Stream, file path, or resource
     * @param int|null $size File size in bytes
     * @param int $error Upload error code (UPLOAD_ERR_*)
     * @param string|null $clientFilename Original client filename
     * @param string|null $clientMediaType Original client media type
     * @throws \InvalidArgumentException If the error code is invalid, a stream
     *     is not readable, or the source type is unsupported
     */
    public function __construct(
        mixed $streamOrFile,
        ?int $size = null,
        int $error = UPLOAD_ERR_OK,
        ?string $clientFilename = null,
        ?string $clientMediaType = null
    ) {
        if (!in_array($error, self::VALID_ERRORS, true)) {
            throw new \InvalidArgumentException("Invalid upload error code: $error (must be a UPLOAD_ERR_* constant)");
        }

        if ($streamOrFile instanceof StreamInterface) {
            if (!$streamOrFile->isReadable()) {
                throw new \InvalidArgumentException('Uploaded file stream must be readable');
            }
            $this->stream = $streamOrFile;
        } elseif (is_resource($streamOrFile)) {
            $stream = Stream::fromResource($streamOrFile);
            if (!$stream->isReadable()) {
                throw new \InvalidArgumentException('Uploaded file resource must be readable');
            }
            $this->stream = $stream;
        } elseif (is_string($streamOrFile) && $streamOrFile !== '') {
            $this->file = $streamOrFile;
        } else {
            throw new \InvalidArgumentException('Uploaded file source must be a StreamInterface, a non-empty file path string, or a resource');
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

    /**
     * @throws \InvalidArgumentException if the $targetPath specified is invalid
     * @throws \RuntimeException on any error during the move, or on subsequent calls
     */
    public function moveTo(string $targetPath): void
    {
        if ($this->moved) {
            throw new RuntimeException('File has already been moved');
        }

        if ($targetPath === '' || str_contains($targetPath, "\0")) {
            throw new \InvalidArgumentException('Target path must be a non-empty string without null bytes');
        }

        if ($this->error !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Cannot move file due to upload error');
        }

        $dir = dirname($targetPath);
        if (! is_dir($dir)) {
            throw new RuntimeException("Target directory does not exist: $dir");
        }

        if ($this->file !== null) {
            $this->moveFile($this->file, $targetPath);
        } elseif ($this->stream !== null) {
            $this->moveStream($targetPath);
        } else {
            throw new RuntimeException('No source file or stream available');
        }

        $this->moved = true;
    }

    /**
     * Move a file-backed upload using the appropriate SAPI-aware mechanism.
     *
     * @throws \RuntimeException
     */
    private function moveFile(string $source, string $targetPath): void
    {
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

        $this->file = $targetPath;
    }

    /**
     * Move a stream-backed upload by writing the stream to the target path.
     *
     * @throws \RuntimeException
     */
    private function moveStream(string $targetPath): void
    {
        $target = fopen($targetPath, 'wb');
        if ($target === false) {
            throw new RuntimeException("Unable to open target path for writing: $targetPath");
        }

        try {
            $stream = $this->stream;
            if ($stream->isSeekable()) {
                $stream->rewind();
            }
            while (!$stream->eof()) {
                $chunk = $stream->read(8192);
                if ($chunk === '') {
                    break;
                }
                if (fwrite($target, $chunk) === false) {
                    throw new RuntimeException("Unable to write to target path: $targetPath");
                }
            }
        } finally {
            fclose($target);
        }

        // The original stream is consumed by the move — detach it.
        $this->stream = null;
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