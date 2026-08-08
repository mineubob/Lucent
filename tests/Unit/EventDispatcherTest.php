<?php

namespace Tests\Unit;

use Lucent\Container\Container;
use Lucent\EventDispatcher\EventDispatcher;
use Lucent\EventDispatcher\ListenerProvider;
use Lucent\EventDispatcher\StoppableEvent;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface;
use Psr\EventDispatcher\StoppableEventInterface;

/**
 * Tests for the event dispatcher and its listener provider.
 */
class EventDispatcherTest extends TestCase
{
    // ─── Fixtures ────────────────────────────────────────────────────────

    private function makeDispatcher(?Container $container = null): EventDispatcher
    {
        $provider = new ListenerProvider();

        return new EventDispatcher($provider, $container ?? new Container());
    }

    // ─── Interface compliance ────────────────────────────────────────────

    public function test_dispatcher_implements_event_dispatcher_interface(): void
    {
        $this->assertInstanceOf(EventDispatcherInterface::class, $this->makeDispatcher());
    }

    public function test_provider_implements_listener_provider_interface(): void
    {
        $this->assertInstanceOf(ListenerProviderInterface::class, new ListenerProvider());
    }

    public function test_stoppable_event_implements_stoppable_event_interface(): void
    {
        $this->assertInstanceOf(StoppableEventInterface::class, new class extends StoppableEvent {
        });
    }

    // ─── Dispatch behaviour ──────────────────────────────────────────────

    public function test_dispatch_returns_the_same_event_instance(): void
    {
        $dispatcher = $this->makeDispatcher();
        $event = new \stdClass();

        $this->assertSame($event, $dispatcher->dispatch($event));
    }

    public function test_dispatch_with_no_listeners_returns_event_untouched(): void
    {
        $dispatcher = $this->makeDispatcher();
        $event = new \stdClass();

        $this->assertSame($event, $dispatcher->dispatch($event));
    }

    public function test_listeners_are_invoked_with_the_event(): void
    {
        $dispatcher = $this->makeDispatcher();
        $dispatcher->getProvider()->listen(\stdClass::class, function (object $received) use (&$seen): void {
            $seen = $received;
        });

        $event = new \stdClass();
        $dispatcher->dispatch($event);

        $this->assertSame($event, $seen);
    }

    public function test_listeners_run_in_priority_order(): void
    {
        $dispatcher = $this->makeDispatcher();
        $order = [];

        $dispatcher->getProvider()->listen(\stdClass::class, function () use (&$order): void {
            $order[] = 'low';
        }, 0);
        $dispatcher->getProvider()->listen(\stdClass::class, function () use (&$order): void {
            $order[] = 'high';
        }, 10);
        $dispatcher->getProvider()->listen(\stdClass::class, function () use (&$order): void {
            $order[] = 'mid';
        }, 5);

        $dispatcher->dispatch(new \stdClass());

        $this->assertSame(['high', 'mid', 'low'], $order);
    }

    public function test_listeners_with_equal_priority_run_in_registration_order(): void
    {
        $dispatcher = $this->makeDispatcher();
        $order = [];

        $dispatcher->getProvider()->listen(\stdClass::class, function () use (&$order): void {
            $order[] = 'first';
        });
        $dispatcher->getProvider()->listen(\stdClass::class, function () use (&$order): void {
            $order[] = 'second';
        });

        $dispatcher->dispatch(new \stdClass());

        $this->assertSame(['first', 'second'], $order);
    }

    public function test_listener_registered_for_parent_class_receives_subclass_event(): void
    {
        $dispatcher = $this->makeDispatcher();
        $seen = false;

        $dispatcher->getProvider()->listen(BaseEvent::class, function () use (&$seen): void {
            $seen = true;
        });

        $dispatcher->dispatch(new ChildEvent());

        $this->assertTrue($seen);
    }

    public function test_listener_registered_for_interface_receives_event(): void
    {
        $dispatcher = $this->makeDispatcher();
        $seen = false;

        $dispatcher->getProvider()->listen(EventInterface::class, function () use (&$seen): void {
            $seen = true;
        });

        $dispatcher->dispatch(new ChildEvent());

        $this->assertTrue($seen);
    }

    public function test_stoppable_event_halts_propagation(): void
    {
        $dispatcher = $this->makeDispatcher();
        $order = [];

        $event = new class extends StoppableEvent {
        };
        $dispatcher->getProvider()->listen($event::class, function ($e) use (&$order): void {
            $order[] = 'stopper';
            $e->stopPropagation();
        });
        $dispatcher->getProvider()->listen($event::class, function () use (&$order): void {
            $order[] = 'after-stop';
        });

        $dispatcher->dispatch($event);

        $this->assertSame(['stopper'], $order);
    }

    public function test_class_string_listener_is_resolved_through_container(): void
    {
        $container = new Container();
        $container->instance(new class {
            public function __invoke(object $event): void
            {
                $event->handled = true;
            }
        }, InvokableListener::class);

        $dispatcher = new EventDispatcher(new ListenerProvider(), $container);
        $dispatcher->getProvider()->listen(\stdClass::class, InvokableListener::class);

        $event = new \stdClass();
        $dispatcher->dispatch($event);

        $this->assertTrue($event->handled);
    }
}

// ─── Fixture classes ────────────────────────────────────────────────────

interface EventInterface
{
}

class BaseEvent
{
}

class ChildEvent extends BaseEvent implements EventInterface
{
}

class InvokableListener
{
    public function __invoke(object $event): void
    {
        $event->handled = true;
    }
}