<?php

namespace Lucent\Http;

class HttpResponse
{
    public function __construct(
        private string|bool $body,
        private int $statusCode,
        private array $headers,
        private string $error = '',
        private int $errorCode = 0
    ) {}

    public function successful(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    public function failed(): bool
    {
        return !$this->successful();
    }

    public function json(): ?array
    {
        if (!$this->body) {
            return null;
        }
        return json_decode($this->body, true);
    }

    public function body(): string
    {
        return $this->body ?: '';
    }

    public function status(): int
    {
        return $this->statusCode;
    }

    public function headers(): array
    {
        return $this->headers;
    }

    public function error(): string
    {
        return $this->error;
    }

    public function errorCode(): int
    {
        return $this->errorCode;
    }
}