<?php

namespace Lucent\Http\EventStream;

/**
 * Represents a single SSE event
 */
readonly class Event
{
    public function __construct(
        public string  $type,
        public array   $data,
        public ?string $id = null,
        public ?int    $retry = null
    ) {}

    /**
     * Convert event to SSE format
     */
    public function toSSE(): string
    {
        $output = '';

        if ($this->id !== null) {
            $output .= "id: {$this->id}\n";
        }

        if ($this->retry !== null) {
            $output .= "retry: {$this->retry}\n";
        }

        $output .= "event: {$this->type}\n";

        // Handle multi-line data
        $jsonData = json_encode($this->data);
        $lines = explode("\n", $jsonData);
        foreach ($lines as $line) {
            $output .= "data: {$line}\n";
        }

        $output .= "\n";

        return $output;
    }

    /**
     * Send this event immediately
     */
    public function send(): void
    {
        echo $this->toSSE();

        if (ob_get_level() > 1) {
            ob_flush();
        }
        flush();
    }

    /**
     * Factory methods for common events
     */
    public static function output(string $line, ?string $id = null): self
    {
        return new self('output', ['line' => $line], $id);
    }

    public static function error(string $message, ?string $id = null): self
    {
        return new self('error', ['message' => $message], $id);
    }

    public static function progress(int $current, int $total, ?string $message = null, ?string $id = null): self
    {
        return new self('progress', [
            'current' => $current,
            'total' => $total,
            'percentage' => round(($current / $total) * 100, 2),
            'message' => $message
        ], $id);
    }

    public static function complete(array $data = [], ?string $id = null): self
    {
        return new self('complete', $data, $id);
    }

    public static function data(string $type, array $data, ?string $id = null): self
    {
        return new self($type, $data, $id);
    }
}