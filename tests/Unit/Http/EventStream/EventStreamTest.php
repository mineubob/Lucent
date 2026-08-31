<?php

namespace Tests\Unit\Http\EventStream;

use Lucent\Http\EventStream\Event;
use Lucent\Http\EventStream\EventStream;
use Lucent\Http\Message\Stream\IteratorStream;
use PHPUnit\Framework\TestCase;

class EventStreamTest extends TestCase
{
    public function test_push_then_stream_yields_events_in_order(): void
    {
        $events = new EventStream();
        $events->push(Event::data('a', ['n' => 1]));
        $events->push(Event::data('b', ['n' => 2]));

        $gen = $events->stream();
        $this->assertSame("event: a\ndata: {\"n\":1}\n\n", $gen->current());
        $gen->next();
        $this->assertSame("event: b\ndata: {\"n\":2}\n\n", $gen->current());
    }

    public function test_stream_yields_event_pushed_after_generator_created(): void
    {
        $events = new EventStream();
        $gen = $events->stream();

        // Push after the generator exists — current() then yields it immediately.

        $events->push(Event::data('x', ['v' => 1]));
        $this->assertSame("event: x\ndata: {\"v\":1}\n\n", $gen->current());
    }

    public function test_close_ends_stream_after_draining_pending_events(): void
    {
        $events = new EventStream();
        $events->push(Event::data('a', ['n' => 1]));
        $events->close();

        $gen = $events->stream();
        $this->assertSame("event: a\ndata: {\"n\":1}\n\n", $gen->current());
        $gen->next();
        $this->assertFalse($gen->valid());
    }

    public function test_close_with_empty_queue_ends_immediately(): void
    {
        $events = new EventStream();
        $events->close();

        $gen = $events->stream();
        $this->assertFalse($gen->valid());
    }

    public function test_push_after_close_is_ignored(): void
    {
        $events = new EventStream();
        $events->close();
        $events->push(Event::data('a', ['n' => 1]));

        $gen = $events->stream();
        $this->assertFalse($gen->valid());
    }

    public function test_response_returns_sse_response(): void
    {
        $events = new EventStream();
        $response = $events->response();

        $this->assertSame('text/event-stream', $response->getHeaderLine('Content-Type'));
        $this->assertSame('no-cache, no-store, must-revalidate', $response->getHeaderLine('Cache-Control'));
        $this->assertInstanceOf(IteratorStream::class, $response->getBody());
    }

    public function test_empty_type_omits_event_field(): void
    {
        $events = new EventStream();
        $events->push(new Event('', ['n' => 1]));

        $gen = $events->stream();
        // No "event:" line — the browser uses its default "message" handler.

        $this->assertSame("data: {\"n\":1}\n\n", $gen->current());
    }

    public function test_signal_handler_wakes_blocked_stream(): void
    {
        if (!function_exists('pcntl_async_signals')) {
            $this->markTestSkipped('pcntl extension required');
        }

        $events = new EventStream();
        $gen = $events->stream();

        // Register a signal handler that pushes an event — this runs while
        // stream() is blocked in stream_select, proving the self-pipe wakes it.

        pcntl_async_signals(true);
        pcntl_signal(SIGUSR1, function () use ($events) {
            $events->push(Event::data('wake', ['from' => 'signal']));
        });

        posix_kill(posix_getpid(), SIGUSR1);

        // The blocked stream() should wake immediately and yield the pushed event..

        $this->assertSame("event: wake\ndata: {\"from\":\"signal\"}\n\n", $gen->current());

        pcntl_async_signals(false);
        pcntl_signal(SIGUSR1, SIG_DFL);
    }
}
