<?php

namespace Lucent\Container;

use Closure;

/**
 * Fluent builder for contextual bindings.
 *
 * Declares that a concrete class should receive a specific implementation for
 * one of its dependencies:
 *
 * ```php
 * $container->when(PhotoController::class)
 *     ->needs(Filesystem::class)
 *     ->give(LocalFilesystem::class);
 * ```
 *
 * The binding is registered on the container when {@see give()} is called.
 */
class ContextualBindingBuilder
{
    /**
     * The container the binding is registered on.
     */
    private Container $container;

    /**
     * The concrete class whose dependency is being overridden.
     */
    private string $concrete;

    /**
     * The abstract dependency being overridden.
     */
    private ?string $needs = null;

    /**
     * @param Container $container The container to register the binding on
     * @param string $concrete The concrete class whose dependency to override
     */
    public function __construct(Container $container, string $concrete)
    {
        $this->container = $container;
        $this->concrete = $concrete;
    }

    /**
     * Declare the abstract dependency to override.
     *
     * @param string $abstract The abstract (interface or class) being overridden
     * @return $this
     */
    public function needs(string $abstract): static
    {
        $this->needs = $abstract;

        return $this;
    }

    /**
     * Register the implementation for the declared dependency.
     *
     * @param string|Closure $implementation The concrete class or factory to give
     * @return void
     */
    public function give(string|Closure $implementation): void
    {
        if ($this->needs === null) {
            throw new ContainerException(
                'ContextualBindingBuilder::give() requires a needs() declaration first.'
            );
        }

        $this->container->addContextualBinding($this->concrete, $this->needs, $implementation);
    }
}