<?php

namespace Lucent\EventDispatcher;

use Lucent\Container\ServiceProvider;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface;

/**
 * Sets up the event dispatcher and its listener provider, and exposes them
 * through the container so they can be resolved by interface.
 */
class EventDispatcherServiceProvider extends ServiceProvider
{
    /**
     * Register the listener provider and event dispatcher as shared instances.
     *
     * The dispatcher is constructed with the listener provider and the
     * container (for resolving class-string listeners).
     */
    public function register(): void
    {
        $provider = new ListenerProvider();
        $dispatcher = new EventDispatcher($provider, $this->container);

        // Register the concrete classes as the shared instances and alias the
        // PSR interfaces to them, so consumers can resolve either and always
        // get the same single registration.
        $this->instance(ListenerProvider::class, $provider);
        $this->alias(ListenerProvider::class, ListenerProviderInterface::class);

        $this->instance(EventDispatcher::class, $dispatcher);
        $this->alias(EventDispatcher::class, EventDispatcherInterface::class);
    }
}
