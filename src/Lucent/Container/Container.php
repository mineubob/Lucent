<?php

namespace Lucent\Container;

use Closure;
use Psr\Container\ContainerInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionClass;
use ReflectionFunction;
use ReflectionNamedType;
use Throwable;

/**
 * A PSR-11 compliant dependency injection container.
 *
 * The container stores shared singletons and lazy factory closures, and
 * resolves them by identifier (a fully-qualified class name or an alias).
 * It replaces the former {@see \Lucent\Service} registry and owns all service
 * storage that previously lived on the {@see \Lucent\Application} singleton.
 *
 * ```php
 * $container = \Lucent\Facades\App::container();
 *
 * $container->instance(Client::class, $httpClient);       // shared object
 * $container->instance($httpClient);                      // shared object, keyed by Client::class
 * $container->singleton(Logger::class);                   // lazy, shared
 * $container->singleton(fn (): Mailer => new Mailer(...)); // lazy, shared, keyed by Mailer::class
 * $container->singleton(Mailer::class, static fn () => new Mailer(...)); // lazy, shared
 *
 * $logger = $container->get(Logger::class);   // resolves via PSR-11
 * $container->has(Logger::class);             // true
 * ```
 *
 * @see https://www.php-fig.org/psr/psr-11/ PSR-11: Container Interface
 */
class Container implements ContainerInterface
{
    /**
     * Instances keyed by identifier.
     *
     * @var array<string, object>
     */
    private array $instances = [];

    /**
     * Lazy factory closures keyed by identifier.
     *
     * Each closure is invoked on first {@see get()}, then the result is
     * cached as a shared singleton and the factory is removed.
     *
     * @var array<string, Closure>
     */
    private array $factories = [];

    /**
     * Non-shared factory closures keyed by identifier.
     *
     * Unlike {@see $factories}, each closure here is invoked on every
     * {@see get()} call, so every resolution returns a fresh instance.
     *
     * @var array<string, Closure>
     */
    private array $bindings = [];

    /**
     * Alias identifiers mapped to the abstract they resolve to.
     *
     * @var array<string, string>
     */
    private array $aliases = [];

    /**
     * Get a container entry by its identifier.
     *
     * Returns the cached instance when present, otherwise invokes and caches
     * a registered factory. Throws a {@see NotFoundException} when no entry
     * exists for the given identifier.
     *
     * @param string $id Identifier (class name or alias) for the entry
     * @return mixed The resolved entry
     * @throws NotFoundExceptionInterface If no entry is found for the identifier
     * @throws ContainerExceptionInterface If the entry exists but cannot be resolved
     */
    public function get(string $id): mixed
    {
        $id = $this->resolveAlias($id);

        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        if (array_key_exists($id, $this->factories)) {
            $factory = $this->factories[$id];
            unset($this->factories[$id]);

            $service = $this->resolveFactory($factory, $id);

            $this->instances[$id] = $service;

            return $service;
        }

        if (array_key_exists($id, $this->bindings)) {
            return $this->resolveFactory($this->bindings[$id], $id);
        }

        throw new NotFoundException($id);
    }

    /**
     * Determine whether the container has an entry for the identifier.
     *
     * @param string $id Identifier (class name or alias) for the entry
     * @return bool True if an entry is (or can be) resolved for the identifier
     */
    public function has(string $id): bool
    {
        $id = $this->resolveAlias($id);

        return array_key_exists($id, $this->instances)
            || array_key_exists($id, $this->factories)
            || array_key_exists($id, $this->bindings);
    }

    /**
     * Register a shared singleton entry.
     *
     * The entry is registered as a lazy factory and only instantiated on the
     * first {@see get()}; the result is then cached as a shared singleton
     * (matching Laravel's lazy singleton behaviour).
     *
     * ```php
     * $container->singleton(Mailer::class);                          // lazy, shared
     * $container->singleton(MailerInterface::class, SmtpMailer::class);
     * $container->singleton(Mailer::class, fn () => new Mailer(...));
     * $container->singleton(fn (): Mailer => new Mailer(...));       // keyed by Mailer::class
     * ```
     *
     * Retrieve the instance with {@see get()}.
     *
     * @param string|callable $abstract Identifier (class name or alias) to register the entry under, or a factory callable whose return type names the identifier
     * @param string|callable|null $concrete Class name to instantiate lazily, or a factory callable returning the instance; defaults to $abstract
     * @return void
     * @throws ContainerExceptionInterface If the class cannot be instantiated, or a callable abstract has no class return type
     */
    public function singleton(string|callable $abstract, string|callable|null $concrete = null): void
    {
        if (is_callable($abstract)) {
            $concrete = $abstract;
            $abstract = $this->abstractFromCallable($abstract);
        }

        $concrete ??= $abstract;
        $this->forget($abstract);

        $this->factories[$abstract] = is_string($concrete)
            ? fn () => $this->newInstance($concrete)
            : Closure::fromCallable($concrete);
    }

    /**
     * Register an existing object instance under an identifier.
     *
     * ```php
     * $container->instance(LoggerInterface::class, $logger);
     * $container->instance($logger); // keyed by Logger::class
     * ```
     *
     * @param string|object $abstract Identifier (class name or alias) to register the instance under, or the instance itself (keyed by its class name)
     * @param object|null $instance The instance to register; required when $abstract is a string
     * @return object The registered instance
     * @throws ContainerExceptionInterface If $abstract is a string and no instance is given
     */
    public function instance(string|object $abstract, ?object $instance = null): object
    {
        if (is_object($abstract)) {
            $instance = $abstract;
            $abstract = $abstract::class;
        }

        if ($instance === null) {
            throw new ContainerException(
                'instance() requires an instance when given a string identifier, e.g. ' .
                'instance(LoggerInterface::class, $logger).'
            );
        }

        $this->forget($abstract);
        $this->instances[$abstract] = $instance;

        return $instance;
    }

    /**
     * Register a non-shared entry backed by a factory.
     *
     * Unlike {@see singleton()}, the factory is invoked on every {@see get()}
     * call, so each resolution returns a fresh instance:
     *
     * ```php
     * $container->bind(Connection::class, static fn () => new Connection($dsn));
     * $container->bind(fn (): Connection => new Connection($dsn)); // keyed by Connection::class
     *
     * $a = $container->get(Connection::class);
     * $b = $container->get(Connection::class); // $a !== $b
     * ```
     *
     * @param string|callable $abstract Identifier (class name or alias) to register the entry under, or a factory callable whose return type names the identifier
     * @param string|callable|null $concrete Class name to instantiate per resolution, or a factory callable; defaults to $abstract
     * @return void
     * @throws ContainerExceptionInterface If a callable abstract has no class return type
     */
    public function bind(string|callable $abstract, string|callable|null $concrete = null): void
    {
        if (is_callable($abstract)) {
            $concrete = $abstract;
            $abstract = $this->abstractFromCallable($abstract);
        }

        $concrete ??= $abstract;
        $this->forget($abstract);

        $this->bindings[$abstract] = is_string($concrete)
            ? fn () => $this->newInstance($concrete)
            : Closure::fromCallable($concrete);
    }

    /**
     * Register an alias so a second identifier resolves to the same entry as
     * an already-registered abstract, without re-instantiating it.
     *
     * ```php
     * $container->singleton(MailerInterface::class, SmtpMailer::class);
     * $container->alias(MailerInterface::class, SmtpMailer::class);
     *
     * $a = $container->get(MailerInterface::class);
     * $b = $container->get(SmtpMailer::class); // $a === $b
     * ```
     *
     * Aliases are resolved lazily at {@see get()} time, so the abstract does
     * not need to be registered yet when the alias is created.
     *
     * @param string $abstract The identifier the alias points to
     * @param string $alias The additional identifier to resolve to $abstract
     * @return void
     */
    public function alias(string $abstract, string $alias): void
    {
        $this->aliases[$alias] = $abstract;
    }

    /**
     * Remove an entry and every alias that resolves to it.
     *
     * The identifier is resolved first (like {@see get()} and {@see has()}),
     * so passing an alias removes the underlying entry and all aliases that
     * point to it. To remove a single alias without touching the entry, use
     * {@see removeAlias()}.
     *
     * ```php
     * $container->singleton(MailerInterface::class, SmtpMailer::class);
     * $container->alias(MailerInterface::class, SmtpMailer::class);
     *
     * $container->remove(MailerInterface::class);
     * $container->has(SmtpMailer::class); // false — alias removed too
     * ```
     *
     * @param string $abstract The identifier (or alias) of the entry to remove
     * @return void
     */
    public function remove(string $abstract): void
    {
        $abstract = $this->resolveAlias($abstract);
        $this->forget($abstract);

        foreach ($this->aliases as $alias => $target) {
            if ($this->resolvesTo($alias, $abstract)) {
                unset($this->aliases[$alias]);
            }
        }
    }

    /**
     * Remove a single alias without touching the entry it points to.
     *
     * ```php
     * $container->singleton(MailerInterface::class, SmtpMailer::class);
     * $container->alias(MailerInterface::class, SmtpMailer::class);
     *
     * $container->removeAlias(SmtpMailer::class);
     * $container->has(SmtpMailer::class); // false
     * $container->has(MailerInterface::class); // true — entry intact
     * ```
     *
     * @param string $alias The alias identifier to remove
     * @return void
     */
    public function removeAlias(string $alias): void
    {
        unset($this->aliases[$alias]);
    }

    /**
     * Remove any existing registration for an identifier.
     *
     * Ensures a given id lives in at most one of {@see $instances},
     * {@see $factories}, or {@see $bindings} at a time, so the most recent
     * registration always wins. Also clears any alias registered *under* the
     * id, but deliberately preserves aliases that point *to* it so the
     * "alias before abstract" pattern survives re-registration.
     *
     * @param string $id The identifier to clear
     * @return void
     */
    private function forget(string $id): void
    {
        unset($this->instances[$id], $this->factories[$id], $this->bindings[$id], $this->aliases[$id]);
    }

    /**
     * Follow the alias chain for an identifier.
     *
     * @param string $id The identifier to resolve
     * @return string The terminal (non-alias) identifier
     */
    private function resolveAlias(string $id): string
    {
        while (isset($this->aliases[$id])) {
            $id = $this->aliases[$id];
        }

        return $id;
    }

    /**
     * Determine whether an alias (transitively) resolves to a target id.
     *
     * @param string $alias The alias to follow
     * @param string $target The id to look for
     * @return bool True if following the chain from $alias reaches $target
     */
    private function resolvesTo(string $alias, string $target): bool
    {
        $seen = [];
        while (isset($this->aliases[$alias]) && !isset($seen[$alias])) {
            $seen[$alias] = true;
            $alias = $this->aliases[$alias];
            if ($alias === $target) {
                return true;
            }
        }

        return false;
    }

    /**
     * Derive an abstract identifier from a factory callable's return type.
     *
     * @param callable $callable The factory callable
     * @return string The class name the callable returns
     * @throws ContainerExceptionInterface If the callable has no class return type
     */
    private function abstractFromCallable(callable $callable): string
    {
        $ref = new ReflectionFunction(Closure::fromCallable($callable));
        $type = $ref->getReturnType();

        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            return $type->getName();
        }

        throw new ContainerException(
            'A callable passed as the abstract must declare a class return type, e.g. ' .
            'singleton(fn (): Mailer => new Mailer(...)).'
        );
    }

    /**
     * Invoke a factory closure, wrapping any resolution failure in a
     * {@see ContainerExceptionInterface}.
     *
     * @param Closure $factory The factory to invoke
     * @param string $id The identifier the factory is registered under
     * @return object The resolved instance
     * @throws ContainerExceptionInterface On resolution failure
     */
    private function resolveFactory(Closure $factory, string $id): object
    {
        try {
            $service = $factory();
        } catch (Throwable $e) {
            throw new ContainerException(
                sprintf('Failed to resolve container factory for "%s": %s', $id, $e->getMessage()),
                0,
                $e
            );
        }

        if (!is_object($service)) {
            throw new ContainerException(
                sprintf('Container factory for "%s" must return an object, "%s" given.', $id, gettype($service))
            );
        }

        return $service;
    }

    /**
     * Instantiate a class, mapping constructor failures to a
     * {@see ContainerExceptionInterface}.
     *
     * @param string $class Class name to instantiate
     * @return object The instantiated instance
     * @throws ContainerExceptionInterface If the class cannot be instantiated
     */
    private function newInstance(string $class): object
    {
        try {
            $reflection = new ReflectionClass($class);

            if (!$reflection->isInstantiable()) {
                throw new ContainerException(
                    sprintf('Class "%s" is not instantiable.', $class)
                );
            }

            $instance = $reflection->newInstance();
        } catch (ContainerException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new ContainerException(
                sprintf('Failed to instantiate "%s": %s', $class, $e->getMessage()),
                0,
                $e
            );
        }

        return $instance;
    }
}
