<?php

namespace Lucent\Filesystem\Exceptions;

use Exception;

class FileNotFound extends Exception
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