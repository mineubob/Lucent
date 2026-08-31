<?php
declare(strict_types=1);


namespace Lucent\Http\EventStream;

/**
 * Represents a single Server-Sent Events (SSE) event.
 *
 * An SSE event is a small, self-contained message pushed over a long-lived
 * HTTP connection. On the wire it looks like:
 *
 *     id: <id>                    (optional — resume/retry cursor)
 *     retry: <ms>                (optional — reconnection delay hint)
 *     event: <type>              (optional — '' omits it; browser then uses its default "message" handler)
 *     data: <line>               (one or more — concatenated with \n)
 *     <blank line>                (terminator — ends the event)
 *
 * Instances are immutable (readonly): build one with the constructor or a
 * factory method, then either push it to an {@see EventStream} bridge
 * (which streams it to the client) or serialize it yourself with
 * {@see toSSE()}.
 *
 * @see EventStream
 */
readonly final class Event
{
    /**
     * @param string $type Event name, emitted as the "event:" field. An
     *                        empty string omits the field entirely, so the
     *                        browser dispatches the event to its default
     *                        "message" handler (addEventListener('message')).
     * @param array $data Event payload. JSON-encoded and emitted as one
     *                        or more "data:" lines (multi-line JSON is split
     *                        per line,, as the SSE spec requires).
     * @param string|null $id Optional SSE "id:" field — a cursor the
     *                        browser sends back on reconnection (Last-Event-ID),
     *                        letting the server resume from where it left off.
     * @param int|null $retry Optional SSE "retry:" field — reconnection
     *                        delay in milliseconds the browser should use
     *                        if the connection drops.
     */
    public function __construct(
        public string  $type,
        public array   $data,
        public ?string $id = null,
        public ?int    $retry = null
    ) {}

    /**
     * Serialize this event to the SSE wire format.
     *
     * Field order is fixed (id, retry,, event,, data) and each field is
     * terminated with a newline. The event ends with a blank line,, which is
     * what tells the browser "this event is complete". The payload is
     * JSON-encoded; if it contains newlines,, each line is emitted as its
     * own "data:" field (the spec concatenates them back with \n on the
     * client side).
     *
     * @return string The complete SSE event,, including the trailing blank line.
     * @throws \RuntimeException If the payload cannot be JSON-encoded
     *                           (e.g. invalid UTF-8 or depth overflow).
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

        if ($this->type !== '') {
            $output .= "event: {$this->type}\n";
        }

        // Handle multi-line data
        $jsonData = json_encode($this->data);
        if ($jsonData === false) {
            throw new \RuntimeException('Unable to JSON-encode event data: ' . json_last_error_msg());
        }
        $lines = explode("\n", $jsonData);
        foreach ($lines as $line) {
            $output .= "data: {$line}\n";
        }

        $output .= "\n";

        return $output;
    }

    /**
     * Create an "output" event — a single line of free-form output.
     *
     * Useful for streaming command/process output to a terminal-style UI.
     *
     * @param string $line The output line to send
     * @param string|null $id Optional SSE id (see constructor).
     * @return self
     */
    public static function output(string $line, ?string $id = null): self
    {
        return new self('output', ['line' => $line], $id);
    }
    /**
     * Create an "error" event — a failure message.
     *
     * @param string $message The error description
     * @param string|null $id Optional SSE id (see constructor).
     * @return self
     */
    public static function error(string $message, ?string $id = null): self
    {
        return new self('error', ['message' => $message], $id);
    }
    /**
     * Create a "progress" event — a progress report for a long-running job.
     *
     * Includes the current/total counts and a pre-computed percentage,, so
     * clients can render a progress bar without doing the math themselves.
     *
     * @param int $current Units completed so far
     * @param int $total Total units to complete
     * @param string|null $message Optional human-readable status text
     * @param string|null $id Optional SSE id (see constructor).
     * @return self
     */
    public static function progress(int $current, int $total, ?string $message = null, ?string $id = null): self
    {
        return new self('progress', [
            'current' => $current,
            'total' => $total,
            'percentage' => round(($current / $total) * 100, 2),
            'message' => $message
        ], $id);
    }

    /**
     * Create a "complete" event — signals that a job finished successfully.
     *
     * @param array $data Optional final payload (e.g. results,, totals).
     * @param string|null $id Optional SSE id (see constructor).
     * @return self
     */
    public static function complete(array $data = [], ?string $id = null): self
    {
        return new self('complete', $data, $id);
    }
    /**
     * Create a custom-named event with an arbitrary payload.
     *
     * The general-purpose factory: pick any event name and the browser can
     * listen for it with addEventListener('<type>', ...). For the spec's
     * default "message" handler,, pass '' as the type.
     *
     * @param string $type Event name; '' omits the "event:" field (see
     *                        constructor).
     * @param array $data Arbitrary payload,, JSON-encoded on the wire
     * @param string|null $id Optional SSE id (see constructor).
     * @return self
     */
    public static function data(string $type, array $data, ?string $id = null): self
    {
        return new self($type, $data, $id);
    }
}
