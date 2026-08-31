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
 *
 * Usage:
 *   $events = new EventStream();
 *   $runner->on('*', fn ($data) => $events->push(Event::data('data', $data))));
 *   return $events->response();
 *
 * Blocking caveat: stream() only produces output when the emitter pulls it.
 * If your controller blocks before returning the response (e.g. a synchronous
 * waitForEvent()), nothing is sent until the generator is advanced. Register
 * listeners and return the response immediately; let the emitter pull events as
 * they arrive.
 */
final class EventStream
{
    private SplQueue $queue;
    private bool $closed = false;

    /** @var resource|null Self-pipe write end — push()/close() signal it */
    private $signalWrite = null;

    /** @var resource|null Self-pipe read end — stream() blocks on it */
    private $signalRead = null;

    public function __construct()
    {
        $this->queue = new SplQueue();

        // Self-pipe trick: a socket pair lets push()/close() wake a
        // blocked stream() immediately — no polling, no lost wakeups.

        // Protocol 0 = no protocol — correct for UNIX domain sockets.
        // (STREAM_IPPROTO_IP is for TCP/IP and only works here by accident
        // on Linux; 0 is portable.)

        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        if ($pair !== false) {
            [$this->signalRead, $this->signalWrite] = $pair;
            stream_set_blocking($this->signalWrite, false);
        }
    }

    /**
     * Queue an event to be streamed. Safe to call from any context
     * (event listener, worker, timer, signal handler). Ignored after close().
     * Wakes a blocked stream() immediately (via the self-pipe).
     */
    public function push(Event $event): void
    {
        if ($this->closed) {
            return;
        }
        $this->queue->enqueue($event->toSSE());
        $this->signal();
    }

    /**
     * End the stream: the generator returns after draining pending events.
     * Wakes a blocked stream() immediately (via the self-pipe).
     *
     * Useful for finite streams (e.g. send N events then finish).
     */
    public function close(): void
    {
        $this->closed = true;
        $this->signal();
    }

    /**
     * Pull queued events as a generator — pass this to withEventStream().
     *
     * Blocks (via stream_select on a self-pipe) until an event is available
     * or the stream is closed — zero idle latency, no polling. If the socket
     * pair could not be created, falls back to a short usleep poll.
     *
     * The self-pipe makes push()/close() from signal handlers, threads, or
     * other processes wake the blocked generator immediately. A signal
     * interrupting stream_select (EINTR) is handled by re-checking the queue.
     *
     * @return Generator
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

            if ($this->signalRead !== null) {
                // Block until push()/close() writes a byte (or a signal
                // interrupts us — then re-check the queue).
                $read = [$this->signalRead];
                $write = null;
                $except = null;
                if (@stream_select($read, $write, $except, null) === false) {
                    continue; // interrupted (e.g. pcntl signal) — re-check
                }
                // Drain the wake-up byte(s) so the next stream_select blocks.

                while (!feof($this->signalRead)) {
                    $chunk = fread($this->signalRead, 8192);
                    if ($chunk === '' || $chunk === false) {
                        break;
                    }
                }
            } else {
                usleep(50_000); // fallback: pair creation failed
            }
        }
    }

    /**
     * Build an SSE response wired to this stream's generator.
     */
    public function response(): Response
    {
        return (new Response())->withEventStream($this->stream());
    }

    /**
     * Wake a blocked stream() by writing a byte to the self-pipe.
     *
     * Non-blocking write; failures (e.g. pipe full) are ignored — the
     * queue is the source of truth, the pipe is just a wake-up signal.
     *
     * @return void
     */
    private function signal(): void
    {
        if ($this->signalWrite === null) {
            return;
        }
        @fwrite($this->signalWrite, "\0");
    }
}
