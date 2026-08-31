<?php
declare(strict_types=1);


namespace Lucent\EventDispatcher;

use Psr\EventDispatcher\StoppableEventInterface;

/**
 * Base class for events whose propagation may be halted.
 *
 * A listener may call {@see stopPropagation()} to prevent any remaining
 * listeners from being invoked for the event. The dispatcher checks
 * {@see isPropagationStopped()} after each listener.
 *
 * ```php
 * class UserCreated extends StoppableEvent
 * {
 *     public function __construct(public readonly User $user) {}
 * }
 * ```
 */
abstract class StoppableEvent implements StoppableEventInterface
{
    /**
     * Whether propagation has been stopped.
     *
     * @var bool
     */
    private bool $propagationStopped = false;

    /**
     * Stop further listeners from being invoked for this event.
     *
     * @return void
     */
    public function stopPropagation(): void
    {
        $this->propagationStopped = true;
    }

    /**
     * Whether propagation has been stopped.
     *
     * @return bool True if no further listeners should be invoked
     */
    public function isPropagationStopped(): bool
    {
        return $this->propagationStopped;
    }
}