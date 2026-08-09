<?php

namespace Lucent\Facades;

use Lucent\Application;

/**
 * Static facade over the application's event dispatcher.
 *
 * ```php
 * Event::listen(UserCreated::class, fn (UserCreated $event) => ...);
 * Event::dispatch(new UserCreated($user));
 * ```
 */
class Event
{
    /**
     * Register a listener for an event.
     *
     * @param class-string $eventClass Event class (or parent class / interface) to listen for
     * @param callable|string $listener Callable, or class-string of an invokable listener
     * @param int $priority Higher priorities run first; defaults to 0
     * @return void
     */
    public static function listen(string $eventClass, callable|string $listener, int $priority = 0): void
    {
        Application::getInstance()->listen($eventClass, $listener, $priority);
    }

    /**
     * Dispatch an event to its registered listeners.
     *
     * @param object $event The event to dispatch
     * @return object The event, possibly modified by listeners
     */
    public static function dispatch(object $event): object
    {
        return Application::getInstance()->dispatch($event);
    }
}