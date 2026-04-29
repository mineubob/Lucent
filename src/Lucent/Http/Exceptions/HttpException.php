<?php

namespace Lucent\Http\Exceptions;

use Lucent\Http\HttpStatus;
use RuntimeException;

class HttpException extends RuntimeException
{
    public function __construct(
        private readonly HttpStatus $status,
        string $message = "",
        ?\Throwable $previous = null
    ) {
        parent::__construct($message ?: $status->message(), 0, $previous);
    }

    public function getStatus(): HttpStatus
    {
        return $this->status;
    }
}