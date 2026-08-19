<?php

namespace Lucent\Container;

use Closure;
use Psr\Container\ContainerInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionClass;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use Throwable;

/**
 * A PSR-11 compliant dependency injection container.
 *
 * The container stores shared singletons, lazy factory closures, non-shared
 * bindings, aliases, contextual bindings, extenders, and resolution hooks. It
 * resolves entries by identifier (a fully-qualified class name or an alias),
 * and can autowire concrete classes by reflecting their constructors.
 *
 * ```php
 * $container = \Lucent\Facades\App::container();
 *
 * $container->instance(Client::class, $httpClient);       // shared object
 * $container->singleton(Logger::class);                   // lazy, shared
 * $container->bind(Connection::class, fn () => new Connection($dsn)); // fresh each get
 *
 * $logger = $container->get(Logger::class);   // resolves via PSR-11
 * $client = $container->make(Client::class);  // autowires constructor deps
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
     * Contextual bindings: concrete => [abstract => implementation].
     *
     * @var array<string, array<string, string|Closure>>
     */
    private array $contextual = [];

    /**
     * Extenders keyed by identifier.
     *
     * Each extender decorates the resolved instance before it is returned.
     *
     * @var array<string, array<int, Closure>>
     */
    private array $extenders = [];

    /**
     * Tags mapping a tag name to the abstracts it contains.
     *
     * @var array<string, array<int, string>>
     */
    private array $tags = [];

    /**
     * Identifiers that have been resolved at least once.
     *
     * @var array<string, bool>
     */
    private array $resolved = [];

    /**
     * Callbacks fired before an instance is returned, keyed by identifier.
     *
     * @var array<string, array<int, Closure>>
     */
    private array $resolvingCallbacks = [];

    /**
     * Callbacks fired after an instance is resolved, keyed by identifier.
     *
     * @var array<string, array<int, Closure>>
     */
    private array $afterResolvingCallbacks = [];

    /**
     * Callbacks fired when an identifier is rebound, keyed by identifier.
     *
     * @var array<string, array<int, Closure>>
     */
    private array $reboundCallbacks = [];

    /**
     * Stack of classes currently being built, for circular detection and
     * contextual binding resolution.
     *
     * @var array<int, string>
     */
    private array $buildStack = [];

    /**
     * Cached reflection parameter plans keyed by "Class::method" or closure id.
     *
     * @var array<string, array<int, array<string, mixed>>>
     */
    private array $reflectionPlans = [];

    /**
     * Abstracts registered as scoped singletons, flushed on {@see flush()}.
     *
     * @var array<int, string>
     */
    private array $scopedInstances = [];

    /**
     * Create a new container.
     *
     * The container registers itself as a resolvable instance so factories
     * and callables can type-hint it for injection.
     */
    public function __construct()
    {
        $this->instance(Container::class, $this);
        $this->instance(ContainerInterface::class, $this);
    }

    /**
     * Get a container entry by its identifier.
     *
     * Resolves in order: cached instance, contextual binding, singleton
     * factory, non-shared binding, then an autowired build. Autowired results
     * are returned fresh (never cached) unless registered as a singleton.
     *
     * @template T
     * @param class-string<T>|string $id Identifier (class name or alias) for the entry
     * @return T The resolved entry
     * @throws NotFoundExceptionInterface If no entry can be resolved
     * @throws ContainerExceptionInterface If the entry exists but cannot be resolved
     */
    public function get(string $id): mixed
    {
        return $this->resolve($id);
    }

    /**
     * Resolve an entry, autowiring its constructor dependencies.
     *
     * Behaves like {@see get()} but is the explicit "build" entry point.
     * Autowired results are returned fresh and never cached unless the entry
     * was registered as a singleton.
     *
     * ```php
     * $service = $container->make(MyService::class); // autowires deps
     * $service = $container->make(MyService::class, ['config' => $config]);
     * ```
     *
     * @template T
     * @param class-string<T>|string $abstract Identifier (class name or alias) to resolve
     * @param array $parameters Explicit values keyed by constructor parameter name
     * @return T The resolved entry
     * @throws ContainerExceptionInterface If the entry cannot be resolved
     */
    public function make(string $abstract, array $parameters = []): mixed
    {
        return $this->resolve($abstract, $parameters);
    }

    /**
     * Determine whether the container can resolve an identifier.
     *
     * Returns true when the identifier is registered (instance, factory,
     * binding, or alias) or when it names an instantiable concrete class that
     * can be autowired.
     *
     * @param string $id Identifier (class name or alias) for the entry
     * @return bool True if an entry can be resolved for the identifier
     */
    public function has(string $id): bool
    {
        $id = $this->resolveAlias($id);

        if (isset($this->instances[$id])
            || isset($this->factories[$id])
            || isset($this->bindings[$id])
            || isset($this->contextual[$id])) {
            return true;
        }

        return $this->isInstantiable($id);
    }

    /**
     * Determine whether an identifier has been explicitly bound.
     *
     * Unlike {@see has()}, this does not consider autowirable concretes — it
     * only reports explicit registrations.
     *
     * @param string $abstract Identifier (class name or alias) to check
     * @return bool True if the identifier is explicitly bound
     */
    public function bound(string $abstract): bool
    {
        return isset($this->instances[$abstract])
            || isset($this->factories[$abstract])
            || isset($this->bindings[$abstract])
            || isset($this->aliases[$abstract]);
    }

    /**
     * Determine whether an identifier has been resolved at least once.
     *
     * @param string $abstract Identifier (class name or alias) to check
     * @return bool True if the identifier has been resolved
     */
    public function resolved(string $abstract): bool
    {
        return isset($this->resolved[$abstract]);
    }

    /**
     * Register a shared singleton entry.
     *
     * The entry is registered as a lazy factory and only instantiated on the
     * first {@see get()}; the result is then cached as a shared singleton.
     * When given a concrete class string, its constructor dependencies are
     * autowired.
     *
     * ```php
     * $container->singleton(Mailer::class);                          // lazy, shared
     * $container->singleton(MailerInterface::class, SmtpMailer::class);
     * $container->singleton(Mailer::class, fn () => new Mailer(...));
     * $container->singleton(fn (): Mailer => new Mailer(...));       // keyed by Mailer::class
     * ```
     *
     * @param string|callable $abstract Identifier (class name or alias) to register the entry under, or a factory callable whose return type names the identifier
     * @param string|callable|null $concrete Class name to instantiate lazily, or a factory callable returning the instance; defaults to $abstract
     * @return void
     * @throws ContainerExceptionInterface If the class cannot be instantiated, or a callable abstract has no class return type
     */
    public function singleton(string|callable $abstract, string|callable|null $concrete = null): void
    {
        if (\is_callable($abstract)) {
            $concrete = $abstract;
            $abstract = $this->abstractFromCallable($abstract);
        }

        $concrete ??= $abstract;
        $this->forget($abstract);

        $this->factories[$abstract] = \is_string($concrete)
            ? fn () => $this->build($concrete)
            : Closure::fromCallable($concrete);
    }

    /**
     * Register a scoped singleton entry.
     *
     * Behaves like {@see singleton()} but the resolved instance is cleared by
     * {@see flush()}, so a new instance is built on the next resolution after
     * a flush.
     *
     * @param string|callable $abstract Identifier (class name or alias) to register the entry under, or a factory callable whose return type names the identifier
     * @param string|callable|null $concrete Class name to instantiate lazily, or a factory callable returning the instance; defaults to $abstract
     * @return void
     * @throws ContainerExceptionInterface If the class cannot be instantiated, or a callable abstract has no class return type
     */
    public function scoped(string|callable $abstract, string|callable|null $concrete = null): void
    {
        if (\is_callable($abstract)) {
            $concrete = $abstract;
            $abstract = $this->abstractFromCallable($abstract);
        }

        $this->scopedInstances[] = $abstract;
        $this->singleton($abstract, $concrete);
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
        if (\is_object($abstract)) {
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
        $this->resolved[$abstract] = true;

        return $instance;
    }

    /**
     * Register a non-shared entry backed by a factory.
     *
     * Unlike {@see singleton()}, the factory is invoked on every {@see get()}
     * call, so each resolution returns a fresh instance. When given a concrete
     * class string, its constructor dependencies are autowired on each build.
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
        if (\is_callable($abstract)) {
            $concrete = $abstract;
            $abstract = $this->abstractFromCallable($abstract);
        }

        $concrete ??= $abstract;
        $this->forget($abstract);

        $this->bindings[$abstract] = \is_string($concrete)
            ? fn () => $this->build($concrete)
            : Closure::fromCallable($concrete);
    }

    /**
     * Register a binding only if the identifier is not already bound.
     *
     * @param string|callable $abstract Identifier (class name or alias) to register the entry under, or a factory callable whose return type names the identifier
     * @param string|callable|null $concrete Class name to instantiate, or a factory callable; defaults to $abstract
     * @return void
     * @throws ContainerExceptionInterface If a callable abstract has no class return type
     */
    public function bindIf(string|callable $abstract, string|callable|null $concrete = null): void
    {
        $key = \is_string($abstract) ? $abstract : null;

        if ($key !== null && $this->bound($key)) {
            return;
        }

        $this->bind($abstract, $concrete);
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
     * Register an extender that decorates a resolved instance.
     *
     * The extender receives the resolved instance and the container, and must
     * return the (possibly decorated) instance. Extenders run after the
     * instance is built and before it is returned.
     *
     * ```php
     * $container->extend(Logger::class, fn (Logger $logger, $container) => new DecoratedLogger($logger));
     * ```
     *
     * @param string $abstract Identifier whose resolved instances to decorate
     * @param Closure $extender Callable receiving (instance, container) and returning the instance
     * @return void
     */
    public function extend(string $abstract, Closure $extender): void
    {
        $this->extenders[$abstract][] = $extender;

        if (isset($this->instances[$abstract])) {
            $this->rebound($abstract);
        }
    }

    /**
     * Assign one or more tags to one or more abstracts.
     *
     * @param string|array $abstracts Identifier(s) to tag
     * @param array $tags Tag name(s) to assign
     * @return void
     */
    public function tag(string|array $abstracts, array $tags): void
    {
        foreach ((array) $abstracts as $abstract) {
            foreach ($tags as $tag) {
                $this->tags[$tag][] = $abstract;
            }
        }
    }

    /**
     * Resolve every abstract assigned to a tag.
     *
     * @param string $tag The tag name
     * @return array<int, mixed> The resolved instances
     * @throws ContainerExceptionInterface If any tagged abstract cannot be resolved
     */
    public function tagged(string $tag): array
    {
        $results = [];

        foreach ($this->tags[$tag] ?? [] as $abstract) {
            $results[] = $this->make($abstract);
        }

        return $results;
    }

    /**
     * Begin a contextual binding for a concrete class.
     *
     * ```php
     * $container->when(PhotoController::class)
     *     ->needs(Filesystem::class)
     *     ->give(LocalFilesystem::class);
     * ```
     *
     * @param string $concrete The class whose dependencies to override
     * @return ContextualBindingBuilder A builder to declare the needs/give pair
     */
    public function when(string $concrete): ContextualBindingBuilder
    {
        return new ContextualBindingBuilder($this, $concrete);
    }

    /**
     * Register a contextual binding.
     *
     * @param string $concrete The class whose dependency to override
     * @param string $abstract The abstract being overridden
     * @param string|Closure $implementation The concrete or factory to give
     * @return void
     */
    public function addContextualBinding(string $concrete, string $abstract, string|Closure $implementation): void
    {
        $this->contextual[$concrete][$abstract] = $implementation;
    }

    /**
     * Register a callback to run before an instance is returned.
     *
     * @param string|callable $abstract Identifier to hook, or a global callback when no identifier is given
     * @param Closure|null $callback The callback receiving (instance, container)
     * @return void
     */
    public function resolving(string|callable $abstract, ?Closure $callback = null): void
    {
        if (\is_string($abstract) && $callback !== null) {
            $this->resolvingCallbacks[$abstract][] = $callback;
        } else {
            $this->resolvingCallbacks['*'][] = $abstract;
        }
    }

    /**
     * Register a callback to run after an instance is resolved.
     *
     * @param string|callable $abstract Identifier to hook, or a global callback when no identifier is given
     * @param Closure|null $callback The callback receiving (instance, container)
     * @return void
     */
    public function afterResolving(string|callable $abstract, ?Closure $callback = null): void
    {
        if (\is_string($abstract) && $callback !== null) {
            $this->afterResolvingCallbacks[$abstract][] = $callback;
        } else {
            $this->afterResolvingCallbacks['*'][] = $abstract;
        }
    }

    /**
     * Register a callback to run when an identifier is rebound.
     *
     * If the identifier is already resolved, the callback fires immediately.
     *
     * @param string $abstract Identifier to watch
     * @param Closure $callback The callback receiving (instance, container)
     * @return void
     */
    public function rebinding(string $abstract, Closure $callback): void
    {
        $this->reboundCallbacks[$abstract][] = $callback;

        if (isset($this->instances[$abstract])) {
            $callback($this->instances[$abstract], $this);
        }
    }

    /**
     * Invoke a callable, resolving its parameters from the container.
     *
     * Each parameter is resolved in order: by name from $parameters, then by
     * type from the container, then a default value, then null if nullable.
     * Otherwise a {@see ContainerException} is thrown. Primitive values are
     * cast to the parameter's declared type where possible.
     *
     * Handlers may be a closure, [Class, 'method'], 'Class@method', or an
     * invokable class/object. Class-based handlers are instantiated via
     * {@see make()} (constructor injection) before the method is invoked.
     *
     * ```php
     * $result = $container->call([$controller, 'show'], ['id' => 5]);
     * $result = $container->call(fn (Logger $log) => $log->info('hi'));
     * ```
     *
     * @template T
     * @param callable(): T|string|array $callback The callable to invoke
     * @param array $parameters Explicit values keyed by parameter name
     * @param string|null $defaultMethod Method to invoke when $callback is an invokable class string
     * @return T The callable's return value
     * @throws ContainerExceptionInterface If a parameter cannot be resolved
     */
    public function call(callable|string|array $callback, array $parameters = [], ?string $defaultMethod = null): mixed
    {
        try {
            [$class, $method] = $this->normalizeHandler($callback, $defaultMethod);

            $plan = $this->getParameterPlan($class, $method);

            $resolved = [];
            foreach ($plan as $param) {
                $resolved[] = $this->resolveParameter($param, $parameters);
            }

            if ($class !== null) {
                $instance = \is_object($class) ? $class : $this->make($class);
                return $instance->{$method}(...$resolved);
            }

            return $method(...$resolved);
        } catch (NotFoundException $e) {
            throw new ContainerException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Remove an entry and every alias that resolves to it.
     *
     * The identifier is resolved first (like {@see get()} and {@see has()}),
     * so passing an alias removes the underlying entry and all aliases that
     * point to it. To remove a single alias without touching the entry, use
     * {@see removeAlias()}.
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
     * @param string $alias The alias identifier to remove
     * @return void
     */
    public function removeAlias(string $alias): void
    {
        unset($this->aliases[$alias]);
    }

    /**
     * Forget a single resolved instance.
     *
     * The next resolution of the identifier will build a fresh instance.
     *
     * @param string $abstract Identifier whose instance to forget
     * @return void
     */
    public function forgetInstance(string $abstract): void
    {
        unset($this->instances[$abstract]);
    }

    /**
     * Forget all resolved instances.
     *
     * @return void
     */
    public function forgetInstances(): void
    {
        $this->instances = [];
    }

    /**
     * Flush the container, clearing instances, bindings, factories, aliases,
     * and resolution state.
     *
     * @return void
     */
    public function flush(): void
    {
        $this->aliases = [];
        $this->resolved = [];
        $this->bindings = [];
        $this->instances = [];
        $this->factories = [];
        $this->scopedInstances = [];
    }

    /**
     * Persist the cached reflection plans to a PHP file.
     *
     * Plans whose defaults contain objects are excluded, as they cannot be
     * represented by var_export.
     *
     * @param string $path The file path to write (e.g. storage/cache/container.php)
     * @return void
     */
    public function cachePlans(string $path): void
    {
        $plans = [];

        foreach ($this->reflectionPlans as $key => $plan) {
            if ($this->isPlanSerializable($plan)) {
                $plans[$key] = $plan;
            }
        }

        $dir = \dirname($path);
        if (!\is_dir($dir)) {
            \mkdir($dir, 0777, true);
        }

        // A marker header lets loadCachedPlans() verify the file was written
        // by cachePlans() before `require`-ing it, so a tampered or
        // attacker-written file in the cache dir cannot execute arbitrary PHP.
        \file_put_contents(
            $path,
            '<?php /* lucent-container-plans v1 */ return ' . \var_export($plans, true) . ';'
        );
    }

    /**
     * Load cached reflection plans from a PHP file.
     *
     * @param string $path The file path to read (e.g. storage/cache/container.php)
     * @return void
     */
    public function loadCachedPlans(string $path): void
    {
        if (!\file_exists($path)) {
            return;
        }

        // Only load plans written by cachePlans() (identified by the marker
        // header) and confined to the project root. `require` executes the
        // file, so a tampered or attacker-written file in the cache dir must
        // never reach it.
        $contents = \file_get_contents($path);
        if ($contents === false || !\str_starts_with($contents, '<?php /* lucent-container-plans v1 */')) {
            return;
        }

        if (!\Lucent\Facades\FileSystem::isWithinRoot($path)) {
            return;
        }

        $plans = require $path;

        if (!\is_array($plans)) {
            return;
        }

        foreach ($plans as $key => $plan) {
            if ($this->isValidPlan($plan)) {
                $this->reflectionPlans[$key] = $plan;
            }
        }
    }

    /**
     * Determine whether a cached plan has the expected structure.
     *
     * Guards against corrupt or version-mismatched cache files injecting
     * malformed plans.
     *
     * @param mixed $plan The plan to validate
     * @return bool True if the plan is a well-formed parameter plan
     */
    private function isValidPlan(mixed $plan): bool
    {
        if (!\is_array($plan)) {
            return false;
        }

        foreach ($plan as $param) {
            if (!\is_array($param)
                || !isset($param['name'], $param['type'], $param['default_available'], $param['default'], $param['variadic'], $param['nullable'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Resolve an entry through the full resolution pipeline.
     *
     * @param string $abstract Identifier to resolve
     * @param array $parameters Explicit constructor values keyed by name
     * @return mixed The resolved entry
     * @throws NotFoundExceptionInterface If no entry can be resolved
     * @throws ContainerExceptionInterface If the entry cannot be built
     */
    private function resolve(string $abstract, array $parameters = []): mixed
    {
        $abstract = $this->resolveAlias($abstract);

        if (isset($this->instances[$abstract]) && !isset($this->aliases[$abstract])) {
            return $this->instances[$abstract];
        }

        $contextual = $this->getContextualConcrete($abstract);
        if ($contextual !== null) {
            return $this->resolveContextual($contextual, $parameters);
        }

        if (isset($this->factories[$abstract])) {
            return $this->resolveFactory($abstract, $parameters);
        }

        if (isset($this->bindings[$abstract])) {
            return $this->resolveBinding($abstract, $parameters);
        }

        return $this->build($abstract, $parameters);
    }

    /**
     * Resolve a singleton factory, caching the result as an instance.
     *
     * @param string $abstract The identifier
     * @param array $parameters Explicit values keyed by name
     * @return object The resolved instance
     * @throws ContainerExceptionInterface On resolution failure
     */
    private function resolveFactory(string $abstract, array $parameters = []): object
    {
        $factory = $this->factories[$abstract];
        unset($this->factories[$abstract]);

        $service = $this->resolveFactoryCallable($factory, $abstract, $parameters);

        $this->instances[$abstract] = $service;
        $this->resolved[$abstract] = true;

        return $service;
    }

    /**
     * Resolve a non-shared binding, returning a fresh instance.
     *
     * @param string $abstract The identifier
     * @param array $parameters Explicit values keyed by name
     * @return object The resolved instance
     * @throws ContainerExceptionInterface On resolution failure
     */
    private function resolveBinding(string $abstract, array $parameters = []): object
    {
        $factory = $this->bindings[$abstract];

        $service = $this->resolveFactoryCallable($factory, $abstract, $parameters);

        $this->resolved[$abstract] = true;

        return $service;
    }

    /**
     * Invoke a factory closure, wrapping any failure in a
     * {@see ContainerExceptionInterface}.
     *
     * @param Closure $factory The factory to invoke
     * @param string $abstract The identifier the factory is registered under
     * @param array $parameters Explicit values keyed by name
     * @return object The resolved instance
     * @throws ContainerExceptionInterface On resolution failure
     */
    private function resolveFactoryCallable(Closure $factory, string $abstract, array $parameters = []): object
    {
        try {
            $service = $this->call($factory, $parameters);
        } catch (Throwable $e) {
            throw new ContainerException(
                \sprintf('Failed to resolve container factory for "%s": %s', $abstract, $e->getMessage()),
                0,
                $e
            );
        }

        if (!\is_object($service)) {
            throw new ContainerException(
                \sprintf('Container factory for "%s" must return an object, "%s" given.', $abstract, \gettype($service))
            );
        }

        return $service;
    }

    /**
     * Resolve a contextual binding implementation.
     *
     * @param string|Closure $implementation The concrete or factory to give
     * @param array $parameters Explicit values keyed by name
     * @return mixed The resolved value
     * @throws ContainerExceptionInterface On resolution failure
     */
    private function resolveContextual(string|Closure $implementation, array $parameters = []): mixed
    {
        if (\is_string($implementation)) {
            return $this->resolve($implementation, $parameters);
        }

        return $this->call($implementation, $parameters);
    }

    /**
     * Find the contextual implementation for an abstract in the current build
     * context.
     *
     * @param string $abstract The abstract being resolved
     * @return string|Closure|null The contextual implementation, or null
     */
    private function getContextualConcrete(string $abstract): string|Closure|null
    {
        $concrete = \end($this->buildStack);

        if ($concrete === false) {
            return null;
        }

        return $this->contextual[$concrete][$abstract] ?? null;
    }

    /**
     * Autowire a concrete class by reflecting its constructor.
     *
     * @param string $concrete The class to instantiate
     * @param array $parameters Explicit constructor values keyed by name
     * @return object The instantiated instance
     * @throws ContainerExceptionInterface If the class cannot be instantiated
     */
    private function build(string $concrete, array $parameters = []): object
    {
        if (!\class_exists($concrete)) {
            throw new NotFoundException($concrete);
        }

        $reflection = new ReflectionClass($concrete);

        if (!$reflection->isInstantiable()) {
            throw new ContainerException(\sprintf('Target [%s] is not instantiable.', $concrete));
        }

        if (\in_array($concrete, $this->buildStack, true)) {
            throw new ContainerException(
                \sprintf('Circular dependency detected: %s -> %s', \implode(' -> ', $this->buildStack), $concrete)
            );
        }

        $this->buildStack[] = $concrete;

        try {
            $constructor = $reflection->getConstructor();

            if ($constructor === null) {
                $instance = $reflection->newInstance();
            } else {
                $dependencies = $this->resolveDependencies($constructor->getParameters(), $parameters);
                $instance = $reflection->newInstanceArgs($dependencies);
            }
        } finally {
            \array_pop($this->buildStack);
        }

        $instance = $this->applyExtenders($concrete, $instance);
        $this->fireResolvingCallbacks($concrete, $instance);

        return $instance;
    }

    /**
     * Resolve a list of constructor dependencies.
     *
     * @param array<int, ReflectionParameter> $parameters The constructor parameters
     * @param array $primitives Explicit values keyed by parameter name
     * @return array<int, mixed> The resolved dependency values
     * @throws ContainerExceptionInterface If a dependency cannot be resolved
     */
    private function resolveDependencies(array $parameters, array $primitives = []): array
    {
        $dependencies = [];

        foreach ($parameters as $parameter) {
            $name = $parameter->getName();

            if (\array_key_exists($name, $primitives)) {
                $dependencies[] = $primitives[$name];
                continue;
            }

            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $dependencies[] = $this->resolve($type->getName());
            } elseif ($parameter->isDefaultValueAvailable()) {
                $dependencies[] = $parameter->getDefaultValue();
            } elseif ($type !== null && $type->allowsNull()) {
                $dependencies[] = null;
            } else {
                throw new ContainerException(
                    \sprintf('Unresolvable dependency [%s] in class [%s].', $name, $parameter->getDeclaringClass()?->getName())
                );
            }
        }

        return $dependencies;
    }

    /**
     * Apply registered extenders to a resolved instance.
     *
     * @param string $abstract The identifier
     * @param object $instance The resolved instance
     * @return object The (possibly decorated) instance
     */
    private function applyExtenders(string $abstract, object $instance): object
    {
        foreach ($this->extenders[$abstract] ?? [] as $extender) {
            $instance = $extender($instance, $this);
        }

        return $instance;
    }

    /**
     * Fire resolving and after-resolving callbacks for an instance.
     *
     * @param string $abstract The identifier
     * @param object $instance The resolved instance
     * @return void
     */
    private function fireResolvingCallbacks(string $abstract, object $instance): void
    {
        $this->fireCallbackArray($instance, $this->resolvingCallbacks[$abstract] ?? []);
        $this->fireCallbackArray($instance, $this->resolvingCallbacks['*'] ?? []);

        $this->resolved[$abstract] = true;

        $this->fireCallbackArray($instance, $this->afterResolvingCallbacks[$abstract] ?? []);
        $this->fireCallbackArray($instance, $this->afterResolvingCallbacks['*'] ?? []);
    }

    /**
     * Invoke a list of callbacks with an instance.
     *
     * @param object $instance The instance to pass
     * @param array<int, Closure> $callbacks The callbacks to invoke
     * @return void
     */
    private function fireCallbackArray(object $instance, array $callbacks): void
    {
        foreach ($callbacks as $callback) {
            $callback($instance, $this);
        }
    }

    /**
     * Fire rebound callbacks for an identifier.
     *
     * @param string $abstract The identifier
     * @return void
     */
    private function rebound(string $abstract): void
    {
        $instance = $this->instances[$abstract] ?? null;

        foreach ($this->reboundCallbacks[$abstract] ?? [] as $callback) {
            $callback($instance, $this);
        }
    }

    /**
     * Normalize a handler into [class, method] form.
     *
     * @param callable|string|array $callback The handler
     * @param string|null $defaultMethod Method to use for invokable class strings
     * @return array{0: string|object|null, 1: string|Closure} The normalized handler
     */
    private function normalizeHandler(callable|string|array $callback, ?string $defaultMethod = null): array
    {
        if (\is_string($callback) && \str_contains($callback, '@')) {
            [$class, $method] = \explode('@', $callback, 2);
            return [$class, $method];
        }

        if (\is_array($callback)) {
            return [$callback[0], $callback[1]];
        }

        if (\is_string($callback)) {
            return [$callback, $defaultMethod ?? '__invoke'];
        }

        if (\is_object($callback) && !$callback instanceof Closure) {
            return [$callback, '__invoke'];
        }

        return [null, $callback];
    }

    /**
     * Get (and cache) the parameter plan for a callable.
     *
     * @param string|object|null $class The class, or null for a closure
     * @param string|Closure $method The method name or closure
     * @return array<int, array<string, mixed>> The parameter plan
     */
    private function getParameterPlan(string|object|null $class, string|Closure $method): array
    {
        $key = $this->planKey($class, $method);

        if (isset($this->reflectionPlans[$key])) {
            return $this->reflectionPlans[$key];
        }

        $reflection = $this->getReflection($class, $method);
        $plan = [];

        foreach ($reflection->getParameters() as $parameter) {
            $plan[] = [
                'name' => $parameter->getName(),
                'type' => $this->getTypeName($parameter),
                'default_available' => $parameter->isDefaultValueAvailable(),
                'default' => $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null,
                'variadic' => $parameter->isVariadic(),
                'nullable' => $parameter->allowsNull(),
            ];
        }

        return $this->reflectionPlans[$key] = $plan;
    }

    /**
     * Build a cache key for a parameter plan.
     *
     * @param string|object|null $class The class, or null for a closure
     * @param string|Closure $method The method name or closure
     * @return string The cache key
     */
    private function planKey(string|object|null $class, string|Closure $method): string
    {
        if ($method instanceof Closure) {
            return 'closure:' . \spl_object_id($method);
        }

        if ($class !== null) {
            return (\is_object($class) ? \get_class($class) : $class) . '::' . $method;
        }

        return $method;
    }

    /**
     * Get the reflection for a callable.
     *
     * @param string|object|null $class The class, or null for a closure
     * @param string|Closure $method The method name or closure
     * @return ReflectionMethod|ReflectionFunction The reflection
     */
    private function getReflection(string|object|null $class, string|Closure $method): ReflectionMethod|ReflectionFunction
    {
        if ($method instanceof Closure) {
            return new ReflectionFunction($method);
        }

        if ($class !== null) {
            return new ReflectionMethod($class, $method);
        }

        return new ReflectionFunction($method);
    }

    /**
     * Resolve a single parameter from the plan.
     *
     * @param array<string, mixed> $param The parameter plan entry
     * @param array $parameters Explicit values keyed by name
     * @return mixed The resolved value
     * @throws ContainerExceptionInterface If the parameter cannot be resolved
     */
    private function resolveParameter(array $param, array $parameters): mixed
    {
        $name = $param['name'];
        $type = $param['type'];

        if (\array_key_exists($name, $parameters)) {
            return $this->castParameter($parameters[$name], $type);
        }

        if ($type !== null && $this->has($type)) {
            return $this->get($type);
        }

        if ($param['default_available']) {
            return $param['default'];
        }

        if ($param['nullable']) {
            return null;
        }

        throw new ContainerException(
            \sprintf('Unable to resolve parameter [%s] of type [%s].', $name, $type ?? 'mixed')
        );
    }

    /**
     * Cast a value to a parameter's declared type where possible.
     *
     * @param mixed $value The value to cast
     * @param string|null $type The declared type name, or null
     * @return mixed The cast value
     */
    private function castParameter(mixed $value, ?string $type): mixed
    {
        if ($type === null || \is_object($value) || \is_array($value)) {
            return $value;
        }

        return match ($type) {
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => \filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $value,
            'string' => (string) $value,
            default => $value,
        };
    }

    /**
     * Get the named type of a parameter, or null.
     *
     * @param ReflectionParameter $parameter The parameter
     * @return string|null The type name, or null
     */
    private function getTypeName(ReflectionParameter $parameter): ?string
    {
        $type = $parameter->getType();

        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            return $type->getName();
        }

        return null;
    }

    /**
     * Determine whether a class exists and is instantiable.
     *
     * @param string $class The class name
     * @return bool True if the class is instantiable
     */
    private function isInstantiable(string $class): bool
    {
        if (!\class_exists($class)) {
            return false;
        }

        return (new ReflectionClass($class))->isInstantiable();
    }

    /**
     * Determine whether a parameter plan can be serialized to a file.
     *
     * @param array<int, array<string, mixed>> $plan The plan
     * @return bool True if all defaults are serializable
     */
    private function isPlanSerializable(array $plan): bool
    {
        foreach ($plan as $param) {
            if (\is_object($param['default'])) {
                return false;
            }
        }

        return true;
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
}
