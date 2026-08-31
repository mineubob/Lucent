<?php
declare(strict_types=1);


namespace Lucent\Filesystem\Exceptions;

use RuntimeException;

class FileNotFound extends RuntimeException
{
    public function __construct(
        private readonly string $filePath,
        ?\Throwable $previous = null
    ) {
        parent::__construct("File not found: {$filePath}", 0, $previous);
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }
}