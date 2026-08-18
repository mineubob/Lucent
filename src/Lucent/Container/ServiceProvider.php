<?php

namespace Lucent\Container;

/**
 * Base class for service providers.
 *
 * A provider is the wiring layer between a subsystem and the container: it
 * registers the subsystem's services in {@see register()} (before anything is
 * resolved) and optionally hooks things up in {@see boot()} (after all
 * providers have registered).
 *
 * ```php
 * class MailerServiceProvider extends ServiceProvider
 * {
 *     public function register(): void
 *     {
 *         $this->singleton(MailerInterface::class, SmtpMailer::class);
 *         $this->bind(Connection::class, fn () => new Connection($this->container->get('dsn')));
 *     }
 *
 *     public function boot(): void
 *     {
 *         $this->call([$this, 'registerRoutes']); // deps resolved via container
 *     }
 * }
 * ```
 *
 * The base class only depends on the {@see Container}, never on the
 * application, so providers can be shipped and consumed by any package that
 * depends on the container.
 */
abstract class ServiceProvider
{
    /**
     * The container the provider registers services on.
     */
    protected Container $container;

    /**
     * Whether the provider is deferred (loaded lazily on first resolution of
     * one of its {@see provides()} services).
     *
     * @var bool
     */
    protected bool $defer = false;

    /**
     * @param Container $container The container to register services on
     */
    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * Register services with the container.
     *
     * Runs before anything is resolved, so only bindings should be declared
     * here — never resolved.
     *
     * @return void
     */
    abstract public function register(): void;

    /**
     * Boot the provider after all providers have registered.
     *
     * Safe to resolve services here, because every binding now exists.
     *
     * @return void
     */
    public function boot(): void
    {
    }

    /**
     * The services this provider provides.
     *
     * Used for deferred loading: when one of these abstracts is resolved, the
     * provider is loaded and registered.
     *
     * @return array<int, string> The provided service identifiers
     */
    public function provides(): array
    {
        return [];
    }

    /**
     * Whether the provider is deferred.
     *
     * @return bool True if the provider loads lazily
     */
    public function isDeferred(): bool
    {
        return $this->defer;
    }

    /**
     * Invoke a callable, resolving its parameters from the container.
     *
     * Internal helper for providers; passthrough to {@see Container::call()}.
     *
     * @param callable|string|array $callback The callable to invoke
     * @param array $parameters Explicit values keyed by parameter name
     * @param string|null $defaultMethod Method to invoke when $callback is an invokable class string
     * @return mixed The callable's return value
     */
    protected function call(callable|string|array $callback, array $parameters = [], ?string $defaultMethod = null): mixed
    {
        return $this->container->call($callback, $parameters, $defaultMethod);
    }

    /**
     * Resolve a service from the container, autowiring its dependencies.
     *
     * Internal helper for providers; passthrough to {@see Container::make()}.
     *
     * @param string $abstract Identifier (class name or alias) to resolve
     * @param array $parameters Explicit values keyed by constructor parameter name
     * @return mixed The resolved entry
     */
    protected function make(string $abstract, array $parameters = []): mixed
    {
        return $this->container->make($abstract, $parameters);
    }

    /**
     * Register a non-shared binding.
     *
     * Internal helper for providers; passthrough to {@see Container::bind()}.
     *
     * @param string|callable $abstract Identifier (class name or alias) to register the entry under, or a factory callable whose return type names the identifier
     * @param string|callable|null $concrete Class name to instantiate per resolution, or a factory callable; defaults to $abstract
     * @return void
     */
    protected function bind(string|callable $abstract, string|callable|null $concrete = null): void
    {
        $this->container->bind($abstract, $concrete);
    }

    /**
     * Register a shared singleton.
     *
     * Internal helper for providers; passthrough to {@see Container::singleton()}.
     *
     * @param string|callable $abstract Identifier (class name or alias) to register the entry under, or a factory callable whose return type names the identifier
     * @param string|callable|null $concrete Class name to instantiate lazily, or a factory callable returning the instance; defaults to $abstract
     * @return void
     */
    protected function singleton(string|callable $abstract, string|callable|null $concrete = null): void
    {
        $this->container->singleton($abstract, $concrete);
    }

    /**
     * Register an existing object instance.
     *
     * Internal helper for providers; passthrough to {@see Container::instance()}.
     *
     * @param string|object $abstract Identifier (class name or alias) to register the instance under, or the instance itself (keyed by its class name)
     * @param object|null $instance The instance to register; required when $abstract is a string
     * @return object The registered instance
     */
    protected function instance(string|object $abstract, ?object $instance = null): object
    {
        return $this->container->instance($abstract, $instance);
    }

    /**
     * Register an alias so a second identifier resolves to the same entry as
     * an already-registered abstract.
     *
     * Internal helper for providers; passthrough to {@see Container::alias()}.
     *
     * @param string $abstract The identifier the alias points to
     * @param string $alias The additional identifier to resolve to $abstract
     * @return void
     */
    protected function alias(string $abstract, string $alias): void
    {
        $this->container->alias($abstract, $alias);
    }

    /**
     * Begin a contextual binding for a concrete class.
     *
     * Internal helper for providers; passthrough to {@see Container::when()}.
     *
     * @param string $concrete The class whose dependencies to override
     * @return ContextualBindingBuilder A builder to declare the needs/give pair
     */
    protected function when(string $concrete): ContextualBindingBuilder
    {
        return $this->container->when($concrete);
    }

    /**
     * Assign tags to abstracts.
     *
     * Internal helper for providers; passthrough to {@see Container::tag()}.
     *
     * @param string|array $abstracts Identifier(s) to tag
     * @param array $tags Tag name(s) to assign
     * @return void
     */
    protected function tag(string|array $abstracts, array $tags): void
    {
        $this->container->tag($abstracts, $tags);
    }

    /**
     * Resolve every abstract assigned to a tag.
     *
     * Internal helper for providers; passthrough to {@see Container::tagged()}.
     *
     * @param string $tag The tag name
     * @return array<int, mixed> The resolved instances
     */
    protected function tagged(string $tag): array
    {
        return $this->container->tagged($tag);
    }
}
