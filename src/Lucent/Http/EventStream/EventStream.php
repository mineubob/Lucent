<?php
declare(strict_types=1);


namespace Lucent\Http\EventStream;

use Generator;
use Lucent\Http\Message\Response;
use SplQueue;

/**
 * Bridges push-style event sources to the pull-based IteratorStream.
 *
 * An event emitter (or any async source) calls push() from any context;
 * the generator returned by stream() pulls those events one at a time, so
 * withEventStream() flushes each as it arrives. This avoids the old
 * callback form's self-flush hack and the (function () {})() IIFE pattern.

 * Usage:
 *   $events = new EventStream();
 *   $runner->on('*', fn ($data) => $events->push(Event::data('data', $data))));
 *   return $events->response();
 */
final class EventStream
{
    private SplQueue $queue;
    private bool $closed = false;

    public function __construct()
    {
        $this->queue = new SplQueue();
    }

    /**
     * Queue an event to be streamed. Safe to call from any context
     * (event listener, worker, timer). Ignored after close().
     */
    public function push(Event $event): void
    {
        if ($this->closed) {
            return;
        }
        $this->queue->enqueue($event->toSSE());
    }

    /**
     * End the stream: the generator returns after draining pending events.

     * Useful for finite streams (e.g. send N events then finish).
     */
    public function close(): void
    {
        $this->closed = true;
    }

    /**
     * Pull queued events as a generator — pass this to withEventStream().
     *
     * Blocks (via a short poll) until an event is available or the stream
     * is closed. If your event source can signal readiness (e.g. a pipe
     * readable via stream_select), replace the poll with a real blocking wait.
     */
    public function stream(): Generator
    {
        while (true) {
            if (! $this->queue->isEmpty()) {
                yield $this->queue->dequeue();
                continue;
            }
            if ($this->closed) {
                return;
            }
            usleep(50_000);
        }
    }

    /**
     * Build an SSE response wired to this stream's generator.
     */
    public function response(): Response
    {
        return (new Response())->withEventStream($this->stream());
    }
}