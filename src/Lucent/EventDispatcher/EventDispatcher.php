<?php

namespace Lucent\EventDispatcher;

use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\StoppableEventInterface;

/**
 * Dispatches events to the listeners registered for them.
 *
 * The dispatcher asks a {@see ListenerProvider} for the listeners applicable
 * to an event and invokes each in turn, passing the event. Listeners may
 * mutate the event; the same instance is returned once all listeners have
 * run. If the event implements {@see StoppableEventInterface} and marks
 * itself stopped, no further listeners are invoked.
 *
 * ```php
 * $dispatcher = new EventDispatcher($provider, $container);
 *
 * $result = $dispatcher->dispatch(new UserCreated($user));
 * ```
 */
class EventDispatcher implements EventDispatcherInterface
{
    /**
     * @param ListenerProvider $provider Supplies listeners for an event
     * @param ContainerInterface $container Resolves class-string listeners
     */
    public function __construct(
        private readonly ListenerProvider $provider,
        private readonly ContainerInterface $container,
    ) {
    }

    /**
     * Provide all relevant listeners with an event to process.
     *
     * @param object $event The event to dispatch
     * @return object The event, possibly modified by listeners
     */
    public function dispatch(object $event): object
    {
        foreach ($this->provider->getListenersForEvent($event) as $listener) {
            if ($event instanceof StoppableEventInterface && $event->isPropagationStopped()) {
                break;
            }

            $this->invoke($listener, $event);
        }

        return $event;
    }

    /**
     * Get the listener provider backing this dispatcher.
     *
     * @return ListenerProvider The provider supplying listeners
     */
    public function getProvider(): ListenerProvider
    {
        return $this->provider;
    }

    /**
     * Invoke a single listener with the event.
     *
     * Class-string listeners are resolved through the container so their
     * dependencies are injected; the resolved instance is expected to be
     * invokable.
     *
     * @param callable|string $listener The listener to invoke
     * @param object $event The event to pass
     * @return void
     */
    private function invoke(callable|string $listener, object $event): void
    {
        if (is_string($listener)) {
            $listener = $this->container->get($listener);
        }

        $listener($event);
    }
}