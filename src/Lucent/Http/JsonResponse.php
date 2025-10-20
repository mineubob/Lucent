<?php

namespace Lucent\Http;

class JsonResponse extends HttpResponse
{
    public protected(set) array $body;

    public function __construct($_ = '', $status = 200)
    {
        parent::__construct("", $status);

        $this->body = [];
        $this->body["message"] = "Request successfully executed.";
        $this->body["outcome"] = true;
        $this->body["status"] = $status;
        $this->body["content"] = [];

        $this->headers["Content-Type"] = "application/json; charset=utf-8";
    }

    public function setOutcome(bool $outcome): JsonResponse
    {
        $this->body["outcome"] = $outcome;
        return $this;
    }

    public function getOutcome()
    {
        return $this->body["outcome"];
    }

    public function setMessage(string $message): JsonResponse
    {
        $this->body["message"] = $message;
        return $this;
    }

    public function setStatusCode(int $statusCode): JsonResponse
    {
        $this->statusCode = $statusCode;
        $this->body["status"] = $statusCode;
        return $this;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function addContent(string $key, $content): JsonResponse
    {
        $this->body["content"][$key] = $content;
        return $this;
    }

    public function setContent(array $content): JsonResponse
    {
        $this->body = $content;
        return $this;
    }

    public function addError(string $key, $error): JsonResponse
    {
        $this->body["outcome"] = false;
        $this->statusCode = 400;
        $this->body["errors"][$key] = $error;
        return $this;
    }

    public function addErrors(array $errors, $message = ""): JsonResponse
    {
        $this->body["outcome"] = false;
        $this->statusCode = 400;
        foreach ($errors as $error) {
            $this->body[$error] = $message;

        }
        return $this;
    }

    public function body(): string|null
    {
        if ($this->statusCode !== $this->body["status"])
            $this->body["status"] = $this->statusCode;

        return json_encode($this->body);
    }
}
