<?php

namespace Lucent\Container;

use Closure;
use Psr\Container\ContainerInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionClass;
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
 * $container->instance($httpClient, Psr18Client::class); // shared object
 * $container->singleton(Logger::class);                  // eager, shared
 * $container->singleton(static fn () => new Mailer(...)); // lazy, shared
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
        return array_key_exists($id, $this->instances)
            || array_key_exists($id, $this->factories)
            || array_key_exists($id, $this->bindings);
    }

    /**
     * Register a shared singleton entry.
     *
     * When given a class-string the class is instantiated eagerly (matching
     * the previous {@see \Lucent\Application::addService()} behaviour). When
     * given a closure, it is stored as a lazy factory and only invoked on the
     * first {@see get()}; the result is then cached as a shared singleton.
     *
     * Retrieve the instance with {@see get()}.
     *
     * @param string|callable $concrete Class name to instantiate, or a factory callable returning the instance
     * @param string|null $alias Optional identifier to register the entry under instead of the default
     * @return void
     * @throws ContainerExceptionInterface If a class-string cannot be instantiated, or a closure is given without an alias
     */
    public function singleton(string|callable $concrete, ?string $alias = null): void
    {
        if (is_string($concrete)) {
            $this->createEager($concrete, $alias);

            return;
        }

        if ($alias === null) {
            throw new ContainerException(
                'A closure passed to singleton() must be registered under an explicit alias, e.g. ' .
                'singleton(fn () => new Mailer(...), Mailer::class).'
            );
        }

        $factory = Closure::fromCallable($concrete);
        $this->forget($alias);
        $this->factories[$alias] = $factory;
    }

    /**
     * Register an existing object instance under an identifier.
     *
     * @param object $service The instance to register
     * @param string|null $alias Optional identifier; defaults to the object's class name
     * @return object The registered instance
     */
    public function instance(object $service, ?string $alias = null): object
    {
        $id = $alias ?? $service::class;
        $this->forget($id);
        $this->instances[$id] = $service;

        return $service;
    }

    /**
     * Register a non-shared entry backed by a factory.
     *
     * Unlike {@see singleton()}, the factory is invoked on every {@see get()}
     * call, so each resolution returns a fresh instance:
     *
     * ```php
     * $container->bind(static fn () => new Connection($dsn));
     *
     * $a = $container->get(Connection::class);
     * $b = $container->get(Connection::class); // $a !== $b
     * ```
     *
     * @param string|callable $concrete Class name to instantiate per resolution, or a factory callable
     * @param string|null $alias Optional identifier to register the entry under
     * @return void
     */
    public function bind(string|callable $concrete, ?string $alias = null): void
    {
        if (is_string($concrete)) {
            $factory = fn () => $this->newInstance($concrete);
            $id = $alias ?? $concrete;
        } else {
            if ($alias === null) {
                throw new ContainerException(
                    'A closure passed to bind() must be registered under an explicit alias, e.g. ' .
                    'bind(fn () => new Connection($dsn), Connection::class).'
                );
            }

            $factory = Closure::fromCallable($concrete);
            $id = $alias;
        }

        $this->forget($id);
        $this->bindings[$id] = $factory;
    }

    /**
     * Eagerly instantiate a class-string and register it as a shared singleton.
     *
     * @param string $class Class name to instantiate
     * @param string|null $alias Optional identifier, defaults to the class name
     * @return object The instantiated instance
     * @throws ContainerExceptionInterface If the class cannot be instantiated
     */
    private function createEager(string $class, ?string $alias = null): object
    {
        $service = $this->newInstance($class);
        $id = $alias ?? $class;
        $this->forget($id);
        $this->instances[$id] = $service;

        return $service;
    }

    /**
     * Remove any existing registration for an identifier.
     *
     * Ensures a given id lives in at most one of {@see $instances},
     * {@see $factories}, or {@see $bindings} at a time, so the most recent
     * registration always wins.
     *
     * @param string $id The identifier to clear
     * @return void
     */
    private function forget(string $id): void
    {
        unset($this->instances[$id], $this->factories[$id], $this->bindings[$id]);
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
