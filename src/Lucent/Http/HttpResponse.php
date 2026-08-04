<?php

namespace Lucent\Http;

class HttpResponse
{

    /**
     * @deprecated Use Lucent\Http\Message\Response instead. Create via Response::json() or new Response().
     */
    public function __construct(
        private string|null $body,
        public protected(set) int $statusCode,
        public protected(set) array $headers = [],
        public protected(set) ?string $error = null,
        public protected(set) int $errorCode = 0
    ) {
        trigger_error(
            'Lucent\\Http\\HttpResponse is deprecated. Use Lucent\\Http\\Message\\Response instead.',
            E_USER_DEPRECATED
        );
    }

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

    public function body(): string|null
    {
        return $this->body;
    }

    public function status(): int
    {
        return $this->statusCode;
    }

    public function headers(): array
    {
        return $this->headers;
    }

    public function error(): ?string
    {
        return $this->error;
    }

    public function errorCode(): int
    {
        return $this->errorCode;
    }
}