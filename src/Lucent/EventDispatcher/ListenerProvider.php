<?php
declare(strict_types=1);


namespace Lucent\EventDispatcher;

use Psr\EventDispatcher\ListenerProviderInterface;
use ReflectionClass;

/**
 * Maps events to the listeners registered for them.
 *
 * Listeners are registered against an event class name and are invoked for
 * that event as well as any subclass of it. Registering a listener against a
 * parent class or interface therefore also receives events of its children.
 *
 * ```php
 * $provider = new ListenerProvider();
 *
 * $provider->listen(UserCreated::class, fn (UserCreated $event) => ...);
 * $provider->listen(UserCreated::class, SendWelcomeMail::class, 10); // priority
 * ```
 *
 * Listeners are returned in priority order (highest first). Listeners sharing
 * a priority are returned in the order they were registered.
 */
class ListenerProvider implements ListenerProviderInterface
{
    /**
     * Registered listeners keyed by event class name.
     *
     * Each entry holds the listener (a callable or a class-string resolved
     * through the container at dispatch time), its priority, and its
     * registration order for stable sorting.
     *
     * @var array<string, list<array{listener: callable|string, priority: int, order: int}>>
     */
    private array $listeners = [];

    /**
     * Monotonic counter used to keep registration order stable.
     *
     * @var int
     */
    private int $order = 0;

    /**
     * Register a listener for an event class.
     *
     * The listener may be a callable (closure, invokable object, function
     * name) or a class-string. Class-strings are resolved through the
     * container when the event is dispatched, so their constructor may
     * receive dependencies.
     *
     * @param class-string $eventClass Event class (or parent class / interface) to listen for
     * @param callable|string $listener Callable, or class-string of an invokable listener
     * @param int $priority Higher priorities run first; defaults to 0
     * @return void
     */
    public function listen(string $eventClass, callable|string $listener, int $priority = 0): void
    {
        $this->listeners[$eventClass][] = [
            'listener' => $listener,
            'priority' => $priority,
            'order' => $this->order++,
        ];
    }

    /**
     * Get the listeners applicable to an event.
     *
     * Collects listeners registered for the event's own class, every parent
     * class, and every implemented interface, then sorts them by priority
     * (descending) and registration order (ascending).
     *
     * @param object $event The event to find listeners for
     * @return iterable<callable> Applicable listeners in invocation order
     */
    public function getListenersForEvent(object $event): iterable
    {
        $matches = [];

        foreach ($this->classesAndInterfaces($event) as $class) {
            foreach ($this->listeners[$class] ?? [] as $entry) {
                $matches[] = $entry;
            }
        }

        usort($matches, static function (array $a, array $b): int {
            return [$b['priority'], $a['order']] <=> [$a['priority'], $b['order']];
        });

        return array_column($matches, 'listener');
    }

    /**
     * Resolve the event's class hierarchy and implemented interfaces.
     *
     * @param object $event The event to inspect
     * @return list<string> Class names, from the event's own class upward
     */
    private function classesAndInterfaces(object $event): array
    {
        $reflection = new ReflectionClass($event);

        $classes = [];
        for ($current = $reflection; $current; $current = $current->getParentClass()) {
            $classes[] = $current->getName();
        }

        return array_merge($classes, $reflection->getInterfaceNames());
    }
}