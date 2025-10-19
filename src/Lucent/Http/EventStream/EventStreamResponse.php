<?php

namespace Lucent\Http\EventStream;

use Lucent\Http\HttpResponse;

class EventStreamResponse extends HttpResponse
{
    private $callback;

    public function __construct(callable $callback)
    {
        parent::__construct('', 200);

        // SSE headers
        $this->headers['Content-Type'] = 'text/event-stream';
        $this->headers['Cache-Control'] = 'no-cache, no-store, must-revalidate';
        $this->headers['X-Accel-Buffering'] = 'no';
        $this->headers['Connection'] = 'keep-alive';

        $this->callback = $callback;
    }

    public function body(): string|null
    {
        if ($this->callback) {
            call_user_func($this->callback);
        }

        return '';
    }
}