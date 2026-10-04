<?php
declare(strict_types=1);


namespace Lucent;

use Lucent\Cache\Cache;
use Lucent\Cache\CacheFactory;
use Lucent\Container\Container;
use Lucent\Container\ServiceProvider;
use Lucent\Commandline\ClearCacheCommand;
use Lucent\Commandline\CliRouter;
use Lucent\Commandline\DeploymentController;
use Lucent\Commandline\GenerateDocumentationCommand;
use Lucent\Commandline\StartDevServerCommand;
use Lucent\Console\SyncCommand;
use Lucent\Console\SyncLegacyCommand;
use Lucent\EventDispatcher\EventDispatcherServiceProvider;
use Lucent\EventDispatcher\ListenerProvider;
use Lucent\Facades\App;
use Lucent\Facades\CommandLine;
use Lucent\Facades\FileSystem;
use Lucent\Facades\Log;
use Lucent\Http\Exceptions\Exceptions;
use Lucent\Http\Exceptions\ExceptionsServiceProvider;
use Lucent\Http\Exceptions\HttpException;
use Lucent\Http\Exceptions\ModelBindingException;
use Lucent\Http\HttpRouter;
use Lucent\Http\HttpStatus;
use Lucent\Http\Message\Response;
use Lucent\Http\Message\ServerRequest;
use Lucent\Http\Middleware\CallbackRequestHandler;
use Lucent\Http\Middleware\MiddlewarePipeline;
use Lucent\Http\RouteInfo;
use Lucent\Logging\Channel;
use Lucent\Logging\Channels\NullChannel;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\Metadata\MetadataFactory;
use BlueprintAU\Radiant\Database;
use Lucent\Support\Attributes\Bind;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\SimpleCache\CacheInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/**
 * Main Application class responsible for handling HTTP requests, console commands,
 * routing, and managing the application lifecycle.
 *
 * This class implements a singleton pattern and serves as the central
 * coordination point for the Lucent framework.
 */
class Application
{
    /**
     * HTTP router instance for handling web requests
     *
     * @var HttpRouter
     */
    public private(set) HttpRouter $httpRouter;

    /**
     * CLI router instance for handling console commands
     *
     * @var CliRouter
     */
    public private(set) CliRouter $consoleRouter;

    /**
     * Array of registered route files
     *
     * @var array
     */
    private array $routes = [];

    /**
     * Array of registered command files
     *
     * @var array
     */
    private array $commands = [];

    /**
     * Whether the application has been booted.
     *
     * @var bool
     */
    private bool $booted = false;

    /**
     * Singleton instance of the Application
     *
     * @var Application|null
     */
    private static ?Application $instance = null;

    /**
     * The dependency injection container for this application.
     *
     * @var Container
     */
    private Container $container;

    /**
     * The application's cache store.
     *
     * Lazily built from the `CACHE_DRIVER` environment variable on first
     * access, or replaced explicitly via {@see setCache()}.
     *
     * @var CacheInterface|null
     */
    private ?CacheInterface $cache = null;

    /**
     * Environment variables loaded from .env file
     *
     * @var array
     */
    private array $env;

    /**
     * Registered logging channels
     *
     * @var array<string, Channel>
     */
    public private(set) array $loggers = [];

    /**
     * Registered error pages
     *
     * @var array<string, ResponseInterface>
     */
    public private(set) array $errorPageResponses;

    /**
     * Fallback route when no error page or route is set.
     */
    public private(set) ?ResponseInterface $fallbackResponse;

    /**
     * An array of globally applicable middleware thats ran for all requests.
     */
    private array $globalMiddlewares = [];

    /**
     * Registered service providers.
     *
     * @var array<int, ServiceProvider>
     */
    private array $providers = [];

    /**
     * Initialize a new Application instance
     *
     * Sets up HTTP and CLI routers, ensures .env file exists,
     * loads environment variables, registers core service providers,
     * and initializes a null logger.
     */
    public function __construct()
    {
        //Create our router instance
        $this->httpRouter = new HttpRouter();
        $this->consoleRouter = new CliRouter();

        //Load the env file if it exists. The .env file is expected to be
        //created by the project (e.g. via create-project template), not by
        //the framework itself.
        $this->loadEnv();

        $this->container = new Container();
        $this->loggers["blank"] = new NullChannel();

        // Register core service providers (clock, event dispatcher,
        // exceptions). Their register() methods bind the shared services
        // onto the container.
        $this->registerProviders();
    }

    /**
     * Get the application's dependency injection container.
     *
     * The container is app-scoped (created in the constructor), so it is
     * naturally reset whenever the application singleton is replaced.
     *
     * @return Container The PSR-11 service container
     */
    public function container(): Container
    {
        return $this->container;
    }

    /**
     * Register a service provider.
     *
     * The provider's {@see ServiceProvider::register()} is invoked immediately
     * so its bindings are available before anything is resolved. Deferred
     * providers are registered as usual, but their boot() is delayed until
     * {@see bootProviders()}.
     *
     * @param ServiceProvider|class-string<ServiceProvider> $provider Provider instance or class name
     * @return ServiceProvider The registered provider
     */
    public function register(ServiceProvider|string $provider): ServiceProvider
    {
        if (is_string($provider)) {
            $provider = new $provider($this->container);
        }

        $this->providers[] = $provider;
        $provider->register();

        return $provider;
    }

    /**
     * Register the application's core service providers.
     *
     * Called from the constructor after the container is created. Each core
     * provider registers its subsystem's services on the container.
     *
     * @return void
     */
    private function registerProviders(): void
    {
        $this->register(EventDispatcherServiceProvider::class);
        $this->register(ExceptionsServiceProvider::class);
    }

    /**
     * Boot all registered service providers.
     *
     * Called at the start of {@see boot()}, after every provider has been
     * registered, so providers can safely resolve services in boot().
     *
     * @return void
     */
    private function bootProviders(): void
    {
        foreach ($this->providers as $provider) {
            $provider->boot();
        }
    }

    /**
     * Resolve a service from the container, autowiring its dependencies.
     *
     * Passthrough to {@see Container::make()}.
     *
     * @param string $abstract Identifier (class name or alias) to resolve
     * @param array $parameters Explicit values keyed by constructor parameter name
     * @return mixed The resolved entry
     */
    public function make(string $abstract, array $parameters = []): mixed
    {
        return $this->container->make($abstract, $parameters);
    }

    /**
     * Invoke a callable, resolving its parameters from the container.
     *
     * Passthrough to {@see Container::call()}.
     *
     * @param callable|string|array $callback The callable to invoke
     * @param array $parameters Explicit values keyed by parameter name
     * @param string|null $defaultMethod Method to invoke when $callback is an invokable class string
     * @return mixed The callable's return value
     */
    public function call(callable|string|array $callback, array $parameters = [], ?string $defaultMethod = null): mixed
    {
        return $this->container->call($callback, $parameters, $defaultMethod);
    }

    /**
     * Resolve the shared exception manager from the container.
     *
     * The manager is registered as a lazy singleton, so it is instantiated on
     * first use and the same instance is returned on every call.
     *
     * @return Exceptions The shared exception manager
     */
    public function exceptions(): Exceptions
    {
        return $this->container->get(Exceptions::class);
    }

    /**
     * Register a listener for an event.
     *
     * Convenience passthrough to the container-registered listener provider.
     *
     * @param class-string $eventClass Event class (or parent class / interface) to listen for
     * @param callable|string $listener Callable, or class-string of an invokable listener
     * @param int $priority Higher priorities run first; defaults to 0
     * @return void
     */
    public function listen(string $eventClass, callable|string $listener, int $priority = 0): void
    {
        $this->container->get(ListenerProvider::class)->listen($eventClass, $listener, $priority);
    }

    /**
     * Dispatch an event to its registered listeners.
     *
     * Convenience passthrough to the container-registered event dispatcher.
     *
     * @param object $event The event to dispatch
     * @return object The event, possibly modified by listeners
     */
    public function dispatch(object $event): object
    {
        return $this->container->get(EventDispatcherInterface::class)->dispatch($event);
    }

    /**
     * Get the application's cache store.
     *
     * Builds the store lazily on first access from the `CACHE_DRIVER`
     * environment variable (defaulting to `file`), then registers it on the
     * container under {@see CacheInterface::class} so it can be resolved via
     * dependency injection. The same instance is returned on subsequent calls.
     *
     * @return CacheInterface The cache store
     */
    public function cache(): CacheInterface
    {
        if ($this->cache === null) {
            $driver = $this->env['CACHE_DRIVER'] ?? 'file';
            $path = $this->env['CACHE_PATH'] ?? 'storage/cache';

            $this->cache = CacheFactory::create($driver, $this->container, $path);

            if ($this->cache instanceof Cache) {
                $defaultTtl = $this->env['CACHE_DEFAULT_TTL'] ?? null;
                $this->cache->setDefaultTtl($defaultTtl === null ? null : (int) $defaultTtl);
            }

            $this->container->instance(CacheInterface::class, $this->cache);
        }

        return $this->cache;
    }

    /**
     * Replace the application's cache store.
     *
     * This is the injection point for third-party cache implementations: any
     * object implementing {@see CacheInterface} can be supplied here. The
     * replacement is also registered on the container under
     * {@see CacheInterface::class}, so dependency-injected consumers resolve
     * the new store.
     *
     * @param CacheInterface $cache The cache store to use
     * @return void
     */
    public function setCache(CacheInterface $cache): void
    {
        $this->cache = $cache;
        $this->container->instance(CacheInterface::class, $cache);
    }

    /**
     * Register a new logging channel.
     *
     * By default the channel is registered under its own name (see
     * Channel::getName()), which is also the key used by getLoggingChannel().
     * Pass $name to override the registry key.
     *
     * @param Channel $log Logger instance
     * @param string|null $name Optional override for the registry key
     * @return void
     */
    public function addLoggingChannel(Channel $log, ?string $name = null): void
    {
        $this->loggers[$name ?? $log->getName()] = $log;
    }

    /**
     * Get a logging channel by key
     *
     * Returns the null logger if the requested channel doesn't exist
     *
     * @param string $key Channel identifier
     * @return Channel Logger instance
     */
    public function getLoggingChannel(string $key): Channel
    {
        if (!array_key_exists($key, $this->loggers)) {
            return $this->loggers["blank"];
        }
        return $this->loggers[$key];
    }

    /**
     * Boot the application.
     *
     * Loads all registered routes and commands, then sets up the database
     * logger. When $autoLoadRoutes / $autoLoadCommands are true (the
     * default), the framework auto-discovers files in the project's
     * `routes/` and `commands/` directories (top-level, non-recursive).
     *
     * Pass false to either param to opt out of auto-discovery and manage
     * loading explicitly via loadRoutes() / CommandLine::register().
     *
     * Idempotent: a second call is a no-op (see $booted guard).
     *
     * @param bool $autoLoadRoutes   Auto-scan RUNNING_LOCATION/routes/*.php
     * @param bool $autoLoadCommands Auto-scan RUNNING_LOCATION/commands/*.php
     * @return void
     */
    public function boot(bool $autoLoadRoutes = true, bool $autoLoadCommands = true): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;

        // Boot registered service providers now that every provider has been
        // registered, so they can safely resolve services.
        $this->bootProviders();

        // Auto-discover route files from the project's routes/ directory.
        if ($autoLoadRoutes) {
            $routesDir = FileSystem::rootPath() . DIRECTORY_SEPARATOR . 'routes' . DIRECTORY_SEPARATOR;
            if (is_dir($routesDir)) {
                foreach (glob($routesDir . '*.php') as $routeFile) {
                    $this->httpRouter->loadRoutes($routeFile);
                }
            }
        }

        // Load explicitly registered route files.
        foreach ($this->routes as $route) {
            $this->httpRouter->loadRoutes($route["file"]);
        }

        // Auto-discover command files from the project's commands/ directory.
        if ($autoLoadCommands) {
            $commandsDir = FileSystem::rootPath() . DIRECTORY_SEPARATOR . 'commands' . DIRECTORY_SEPARATOR;
            if (is_dir($commandsDir)) {
                foreach (glob($commandsDir . '*.php') as $commandFile) {
                    require_once $commandFile;
                }
            }
        }

        // Load explicitly registered command files.
        foreach ($this->commands as $command) {
            require_once $command;
        }
    }

    /**
     * Get environment variables
     *
     * @return array Environment variables
     */
    public function getEnv(): array
    {
        return $this->env;
    }

    /**
     * Get or create the singleton Application instance
     *
     * @return Application The singleton instance
     */
    public static function getInstance(): Application
    {
        if (Application::$instance == null) {
            Application::$instance = new Application();
        }

        return Application::$instance;
    }

    /**
     * Register a route file
     *
     * @param string $route Path to route file
     * @return void
     */
    public function loadRoutes(string $route): void
    {
        // Resolve against the project root so boot() always passes a real
        // absolute filesystem path to the router.  This handles both
        // bare relative paths ("routes/web.php") and paths that look
        // absolute but are really project-relative ("/routes/web.php").
        if (!str_starts_with($route, FileSystem::rootPath())) {
            $route = FileSystem::rootPath() . DIRECTORY_SEPARATOR
                . ltrim($route, DIRECTORY_SEPARATOR);
        }
        $this->routes[] = ["file" => $route];
    }

    /**
     * Execute an HTTP request
     *
     * Process incoming HTTP request and emit the response using streaming-aware
     * emission (reads body stream in a loop with flush, handling both regular
     * and streaming/SSE responses uniformly).
     *
     * @return string html body
     */
    public function executeHttpRequest(): string
    {
        $request = ServerRequest::capture();
        $response = $this->handleHttpRequest($request);

        http_response_code($response->getStatusCode());
        $this->setHeaders($response->getHeaders());

        // Streaming-aware emission
        $body = $response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }

        $chunkSize = 8192;
        while (! $body->eof()) {
            echo $body->read($chunkSize);
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
        }

        return '';
    }


    /**
     * Handle an HTTP request and return a PSR-7 ResponseInterface.
     *
     * Runs the PSR-15 middleware pipeline and dispatches the controller.
     * The request is provided by the caller (see executeHttpRequest(), which
     * builds one from globals, or the MakeRequest test trait).
     *
     * @param ServerRequestInterface $request The PSR-7 request to handle
     * @return ResponseInterface
     */
    public function handleHttpRequest(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $this->boot(true, true);

            // Global middleware runs for EVERY request, including routing
            // failures (404/403) and dispatch errors (500). It wraps the
            // fallback handler below, so it sees every response the app
            // produces and may short-circuit with its own response.
            $middlewareList = [];
            foreach ($this->globalMiddlewares as $middleware) {
                $middlewareList[] = $this->resolveMiddleware($middleware);
            }

            // Fallback handler: route lookup + dispatch + error conversion all
            // happen INSIDE the pipeline so global middleware wraps the errors too.
            $fallback = new CallbackRequestHandler(function (ServerRequestInterface $req): ResponseInterface {
                try {
                    // dispatchRoute() attaches route attributes (routeInfo,
                    // urlVars) to the request passed down the pipeline.
                    return $this->dispatchRoute($req);
                } catch (Throwable $throwable) {
                    $this->exceptions()->reportException($throwable, $req);

                    $response = $this->exceptions()->renderException($throwable, $req);
                    if ($response instanceof ResponseInterface) {
                        return $response;
                    }

                    // Preserve the 4xx status for HttpException, else 500.
                    $status = $throwable instanceof HttpException
                        ? $throwable->getStatus()
                        : HttpStatus::SERVER_ERROR;

                    return $this->responseWithError($status, $throwable);
                }
            });

            $pipeline = new MiddlewarePipeline($middlewareList, $fallback);

            return $pipeline->handle($request);
        } catch (Throwable $throwable) {
            // Exceptions thrown by global middleware itself still produce a
            // 500 response rather than escaping the request handler.
            $this->exceptions()->reportException($throwable, $request);

            $response = $this->exceptions()->renderException($throwable, $request);
            if ($response instanceof ResponseInterface) {
                return $response;
            }

            // Preserve the 4xx status for HttpException, else 500.
            $status = $throwable instanceof HttpException
                ? $throwable->getStatus()
                : HttpStatus::SERVER_ERROR;

            return $this->responseWithError($status, $throwable);
        }
    }

    /**
     * Look up a route and dispatch it to its controller.
     *
     * Runs inside the global middleware pipeline's fallback handler, so route
     * lookup failures (404/403) and dispatch errors are converted to error
     * responses that global middleware still wraps.
     *
     * Route info and URL vars are attached as PSR-7 attributes here, so they
     * are visible to route-scoped middleware and the controller, but NOT to
     * global middleware (which runs before routing).
     *
     * @param ServerRequestInterface $request The PSR-7 request
     * @return ResponseInterface
     */
    private function dispatchRoute(ServerRequestInterface $request): ResponseInterface
    {
        $routeData = $this->httpRouter->AnalyseRouteAndLookup(
            $this->httpRouter->GetUriAsArray($request->getUri()->getPath()),
            $request->getMethod()
        );

        $controller = $this->container->make($routeData["controller"]);

        // Store route info and URL vars as PSR-7 attributes
        $routeInfo = new RouteInfo(
            $routeData["controller"],
            $routeData["method"],
            $routeData["route"],
            $request->getMethod(),
            $routeData["variables"]
        );
        $request = $request
            ->withAttribute('routeInfo', $routeInfo)
            ->withAttribute('urlVars', $routeData["variables"]);

        $method = new ReflectionMethod($routeData["controller"], $routeData["method"]);

        // Build route-scoped middleware pipeline
        $middlewareList = [];
        foreach ($routeData["middleware"] as $middleware) {
            $middlewareList[] = $this->resolveMiddleware($middleware);
        }

        // Build controller dispatch callback
        $dispatchCallback = function (ServerRequestInterface $request) use (
            $method,
            $routeData,
            $controller
        ): ResponseInterface {
            return $this->dispatchController($request,  $method, $routeData, $controller);
        };

        // Wrap dispatch callback as a PSR-15 RequestHandlerInterface
        $pipeline = new MiddlewarePipeline($middlewareList, new CallbackRequestHandler($dispatchCallback));

        return $pipeline->handle($request);
    }

    /**
     * Resolve a middleware entry (instance or class-string) to a MiddlewareInterface.
     *
     * @param MiddlewareInterface|string $middleware Middleware instance or class name
     * @return MiddlewareInterface
     */
    private function resolveMiddleware(MiddlewareInterface|string $middleware): MiddlewareInterface
    {
        if ($middleware instanceof MiddlewareInterface) {
            return $middleware;
        }

        $object = new $middleware();
        if ($object instanceof MiddlewareInterface) {
            return $object;
        }

        throw new \RuntimeException('Unknown middleware type: ' . get_class($object));
    }

    /**
     * Dispatch a matched route to its controller method.
     *
     * The controller is resolved from the container (constructor injection),
     * the PSR-7 request and services are injected, model binding is applied
     * for route parameters, the method is invoked via the container's
     * {@see call()}, and the returned ResponseInterface is validated.
     *
     * @param ServerRequestInterface $request The PSR-7 request
     * @param ReflectionMethod $method Controller method to invoke
     * @param array $routeData Matched route data
     * @param object $controller The container-resolved controller instance
     * @return ResponseInterface
     */
    private function dispatchController(
        ServerRequestInterface $request,
        ReflectionMethod $method,
        array $routeData,
        object $controller
    ): ResponseInterface {
        // Check if method requires a PSR-7 request parameter
        $psr7Injection = $this->requiresPsr7Request($method);

        $variables = $routeData["variables"];

        if ($psr7Injection !== null) {
            $variables[$psr7Injection] = $request;
        }

        // Apply #[Bind] route model binding for parameters that opt in.
        // A Model type-hint WITHOUT #[Bind] is never auto-resolved from the
        // URL — the container resolves it (or the controller fetches it).
        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type === null || !($type instanceof ReflectionNamedType)) {
                continue;
            }

            $typeName = $type->getName();

            if (!is_subclass_of($typeName, Model::class)) {
                continue;
            }

            $instance = $this->resolveModelBinding(
                $parameter,
                $typeName,
                $routeData["variables"],
                $request,
            );
            if ($instance !== null) {
                $variables[$parameter->getName()] = $instance;
            }
        }

        $result = $this->container->call([$controller, $method->getName()], $variables);

        if ($result instanceof ResponseInterface) {
            return $result;
        }

        throw new \RuntimeException(sprintf(
            'Controller must return a %s, got %s.',
            ResponseInterface::class,
            is_object($result) ? get_class($result) : gettype($result)
        ));
    }

    /**
     * Check if a method requires a PSR-7 ServerRequestInterface parameter.
     *
     * @param ReflectionMethod $method Method to check
     * @return string|null Parameter name that should receive the ServerRequest, or null if none
     */
    private function requiresPsr7Request(ReflectionMethod $method): ?string
    {
        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type === null) {
                continue;
            }
            $name = $type->getName();
            if ($name === ServerRequestInterface::class || $name === ServerRequest::class) {
                return $parameter->getName();
            }
        }
        return null;
    }

    /**
     * Resolve a #[Bind] route model binding for a controller parameter.
     *
     * Returns null when the parameter carries no #[Bind] attribute — the
     * Model parameter is then left for the container to resolve (or the
     * controller to fetch). A parameter WITH #[Bind] that cannot be resolved
     * throws HttpException(NOT_FOUND).
     *
     * @param \ReflectionParameter $parameter The controller parameter
     * @param class-string<Model> $type The parameter's model class
     * @param array<string, mixed> $vars The matched route variables
     * @param ServerRequestInterface $request The current request — passed to
     *        resolve/scope/connection callables as their context argument
     * @return Model|null The bound model, or null when no #[Bind] is present
     * @throws ModelBindingException When the lookup finds no row
     */
    private function resolveModelBinding(
        \ReflectionParameter $parameter,
        string $type,
        array $vars,
        ServerRequestInterface $request,
    ): ?Model {
        $attr = $parameter->getAttributes(Bind::class)[0] ?? null;
        if ($attr === null) {
            return null; // no #[Bind] → no auto-binding
        }

        $bind = $attr->newInstance();
        $name = $parameter->getName(); // route variable is always the parameter name

        if (!array_key_exists($name, $vars)) {
            throw new \InvalidArgumentException("Route variable '{$name}' not found for #[Bind]");
        }

        $value = $vars[$name];
        $keys = $this->resolveBindKeys($bind, $type, $vars, $value, $request);

        $resolve = function () use ($type, $keys, $bind, $vars, $value, $request) {
            $query = $type::newQuery();

            foreach ($keys as $column => $columnValue) {
                $query = $query->where($column, '=', $columnValue);
            }

            if ($bind->scope !== null) {
                $query = self::invokeBindCallable($bind->scope, [$query, $value, $vars, $request]);
            }

            return $query->first();
        };

        // Connection splitting: run the lookup on the named connection and
        // restore the previous active connection afterwards (no bleed).
        $conn = $bind->connection;
        if ($conn !== null && !is_string($conn)) {
            $conn = self::invokeBindCallable($conn, [$vars, $request]);
        }

        $instance = $conn !== null
            ? Database::usingConnection($conn, $resolve)
            : $resolve();

        if ($instance === null) {
            throw new ModelBindingException($type, $keys);
        }

        return $instance;
    }

    /**
     * Resolve the [column => value] map for a #[Bind] binding.
     *
     * @param Bind $bind The attribute instance
     * @param class-string<Model> $type The model class
     * @param array<string, mixed> $vars The matched route variables
     * @param mixed $value The route variable's value
     * @param ServerRequestInterface $request The current request — passed to
     *        resolve callables as their context argument
     * @return array<string, mixed>
     * @throws \InvalidArgumentException On a composite PK without an explicit resolve,
     *         a missing route variable, or an empty resolve-callback map
     */
    private function resolveBindKeys(Bind $bind, string $type, array $vars, mixed $value, ServerRequestInterface $request): array
    {
        // Explicit callable: the developer declares every key part's source.
        if ($bind->resolve !== null && !is_string($bind->resolve)) {
            $map = self::invokeBindCallable($bind->resolve, [$vars, $request]);
            if (!is_array($map) || $map === []) {
                throw new \InvalidArgumentException('#[Bind] resolve callback must return a non-empty [column => value] array');
            }
            return $map;
        }

        // Explicit column name: bind that column to the route variable.
        if (is_string($bind->resolve)) {
            return [$bind->resolve => $value];
        }

        // Default: single primary key bound to the route variable.
        $pks = array_map(
            fn($pk) => $pk->name,
            MetadataFactory::for($type)->primaryKeys,
        );

        if (count($pks) !== 1 || $pks[0] === null) {
            throw new \InvalidArgumentException(
                "Model {$type} has a composite primary key; provide #[Bind(resolve: SomeResolver::class)]"
            );
        }

        return [$pks[0] => $value];
    }

    /**
     * Invoke a #[Bind] callable argument.
     *
     * Attribute arguments must be constant expressions, so callables arrive
     * in one of these forms:
     *
     * - an invokable class-string (`SomeScope::class`) — instantiated and
     *   invoked;
     * - a `[Class::class, 'method']` array of constants — instantiated and
     *   the named method invoked;
     * - a Closure (only when the Bind is constructed programmatically —
     *   closures are a compile error inside attribute arguments).
     *
     * @param mixed $callable Invokable class-string, [class, method] array, or Closure
     * @param array<int, mixed> $args Arguments to invoke with
     * @return mixed The callable's return value
     * @throws \InvalidArgumentException When the value is not callable
     */
    private static function invokeBindCallable(mixed $callable, array $args): mixed
    {
        if (is_string($callable) && class_exists($callable)) {
            $callable = new $callable();
        } elseif (is_array($callable)
            && array_keys($callable) === [0, 1]
            && is_string($callable[0])
            && is_string($callable[1])
            && class_exists($callable[0])
        ) {
            $callable = [new $callable[0](), $callable[1]];
        }

        if (!is_callable($callable)) {
            throw new \InvalidArgumentException(
                '#[Bind] callable arguments must be an invokable class-string, a '
                . "[Class::class, 'method'] array, or a Closure; got "
                . get_debug_type($callable) . '.'
            );
        }

        return $callable(...$args);
    }

    /**
     * Load environment variables from a .env file
     *
     * Parses the .env file and populates the env property with key-value pairs.
     * Handles empty lines, comments, and quoted values.
     *
     * The loaded values replace the current in-memory environment (the file is
     * the source of truth). Keys are normalised to upper-case, matching
     * {@see setEnv()}. To overlay individual keys on top of the existing
     * environment instead, use {@see setEnv()}.
     *
     * @param string|null $path Optional path to the .env file. Defaults to
     *                          FileSystem::rootPath()/.env.
     * @return void
     */
    public function loadEnv(?string $path = null): void
    {

        $envPath = $path ?? FileSystem::rootPath() . DIRECTORY_SEPARATOR . ".env";
        $output = [];

        if (file_exists($envPath)) {
            $file = fopen($envPath, "r");

            if ($file) {
                while (($line = fgets($file)) !== false) {
                    // Skip comments and empty lines
                    $line = trim($line);
                    if (empty($line) || str_starts_with($line, '#')) {
                        continue;
                    }

                    // Find position of first equals sign
                    $pos = strpos($line, '=');
                    if ($pos !== false) {
                        $key = trim(substr($line, 0, $pos));
                        $value = trim(substr($line, $pos + 1));

                        // Remove quotes if present
                        $value = trim($value, '"\'');

                        if (!empty($key)) {
                            $output[strtoupper($key)] = $value;
                        }
                    }
                }
                fclose($file);
            }
        }

        $this->env = $output;
        $this->configureDatabase();
    }

    /**
     * Set environment variables in memory.
     *
     * Populates the in-memory environment without touching any .env file on
     * disk. Keys are normalised to upper-case and values cast to string.
     *
     * By default the given values are merged into the existing environment. Pass
     * $merge = false to replace the entire environment with $values instead.
     *
     * Re-configures the database layer with the resulting environment, so it can
     * be used to switch database drivers at runtime (e.g. in tests) without
     * writing a .env file.
     *
     * @param array $values Key-value pairs to set.
     * @param bool  $merge  Whether to merge into the existing environment
     *                      (true) or replace it entirely (false).
     * @return void
     */
    public function setEnv(array $values, bool $merge = true): void
    {
        $normalised = [];
        foreach ($values as $key => $value) {
            $normalised[strtoupper($key)] = (string) $value;
        }

        $this->env = $merge ? array_merge($this->env, $normalised) : $normalised;
        $this->configureDatabase();
    }

    /**
     * Build the Radiant DatabaseManager from the environment and inject it
     * into the Radiant Database facade.
     *
     * Reads the DB_* environment variables (DB_DRIVER, DB_HOST, DB_PORT,
     * DB_DATABASE, DB_USERNAME, DB_PASSWORD, DB_CHARSET) into the Radiant
     * connection config shape. For the sqlite driver, DB_DATABASE is
     * resolved to an absolute filesystem path (non-absolute paths are
     * resolved against {@see FileSystem::rootPath()}, `:memory:` passes
     * through untouched) — Radiant's connector consumes the value as a
     * DSN path verbatim. Called from loadEnv() and setEnv(), so the
     * database layer re-configures whenever the environment changes (e.g.
     * switching drivers at runtime in tests).
     *
     * An existing manager is NEVER replaced — runtime customizations (extra
     * named connections, extended connectors) survive an env reload:
     *
     * - manager with a `default` connection: the config is ALTERED via
     *   {@see DatabaseManager::setConnectionConfig()} — the evicted
     *   connection is rebuilt lazily on next use, so a changed config
     *   (e.g. a new sqlite path) takes full effect;
     * - manager WITHOUT a `default` connection (not built by Lucent): the
     *   config is registered via addConnection() and made active via
     *   {@see DatabaseManager::useConnection()} — switching the active
     *   connection is what makes the env config the default.
     *
     * Note: when DB_DRIVER is absent from the environment, the existing
     * manager is left untouched — an env reload cannot un-configure the
     * database layer.
     *
     * @return void
     */
    private function configureDatabase(): void
    {
        $driver = $this->env['DB_DRIVER'] ?? null;

        if ($driver === null || $driver === '') {
            return; // no database configured — nothing to wire
        }

        $config = ['driver' => $driver];

        foreach (['host', 'port', 'database', 'username', 'password', 'charset'] as $key) {
            $envKey = 'DB_' . strtoupper($key);
            if (isset($this->env[$envKey]) && $this->env[$envKey] !== '') {
                $config[$key] = $key === 'port' ? (int) $this->env[$envKey] : $this->env[$envKey];
            }
        }

        if ($driver === 'sqlite') {
            $this->resolveSqlitePath($config);
        }

        if (!\BlueprintAU\Radiant\Database::hasManager()) {
            // No manager yet — build one with Lucent's default connection.
            \BlueprintAU\Radiant\Database::setManager(
                new \BlueprintAU\Radiant\Database\DatabaseManager(['default' => $config], 'default'),
            );
            return;
        }

        $manager = \BlueprintAU\Radiant\Database::manager();

        if ($manager->hasConnection('default')) {
            // Evicts the resolved connection (rolling back any open
            // transaction first); the next use rebuilds from the new config.
            $manager->setConnectionConfig('default', $config);
            return;
        }

        // A manager exists but was not built by Lucent (no `default`
        // connection) — register the env config and make it the active
        // connection. addConnection() alone would leave the manager using
        // ITS OWN active connection; useConnection() is the switch.
        $manager->addConnection('default', $config);
        $manager->useConnection('default');
    }

    /**
     * Resolve the sqlite "database" config value against the project root.
     *
     * Radiant's SqliteConnector consumes the `database` value verbatim as a
     * PDO DSN path — it has no knowledge of the application's root
     * directory. This restores the pre-Radiant behaviour (and matches
     * Laravel's SQLiteConnector, which resolves via base_path()): relative
     * paths such as "storage/database.sqlite" are resolved against
     * {@see FileSystem::rootPath()}, absolute paths pass through, and the
     * `:memory:` in-memory sentinel is handed to the connector untouched.
     * `..` segments are collapsed lexically (no filesystem access, so the
     * path may not exist yet).
     *
     * @param  array<string, mixed>  $config  The default connection config;
     *         `database` is replaced in place when it is a relative path.
     * @return void
     */
    private function resolveSqlitePath(array &$config): void
    {
        $path = $config['database'] ?? null;

        if (!is_string($path) || $path === '' || $path === ':memory:') {
            return; // absent/invalid lets the connector's fail-fast validation handle it
        }

        $config['database'] = FileSystem::normalizePath(FileSystem::absolutePath($path));
    }

    /**
     * Execute a console command
     *
     * Registers built-in commands, analyzes the command input,
     * validates the controller and method, and executes the command.
     *
     * @param array $args Command line arguments
     * @return string Command output
     * @throws ReflectionException
     */
    public function executeConsoleCommand(array $args = []): string
    {
        $this->boot(true, true);

        if (!CommandLine::isCaptured()) {
            ob_implicit_flush(true);
            if (ob_get_level() > 0) {
                ob_end_flush();
            }
        }

        CommandLine::register(GenerateDocumentationCommand::$command, "generateApi", GenerateDocumentationCommand::class, "Generates API documentation based on your controller attributes");
        CommandLine::register(SyncCommand::$command, "run", SyncCommand::class, "Sync the discovered models' schema to the database (diff-based, destructive prompts). Options: --filter= --exclude-filter= --dir= --force --dry-run --no-transactional --no-drop-tables");
        CommandLine::register(SyncLegacyCommand::$command, "run", SyncLegacyCommand::class, "DEPRECATED one-time migration: rename legacy table names to the Radiant naming. Options: --filter= --exclude-filter= --dir= --dry-run");
        CommandLine::register(StartDevServerCommand::$command, "start", StartDevServerCommand::class, "Start the built-in PHP development server");
        CommandLine::register(DeploymentController::$command_latest,   "latest",   DeploymentController::class, "Downloads and deploys the latest project release");
        CommandLine::register(DeploymentController::$command_rollback, "rollback", DeploymentController::class, "Rolls back to the most recent backup");
        CommandLine::register(ClearCacheCommand::$command, "clear", ClearCacheCommand::class, "Clears the application cache");
        if ($args === []) {
            $args = array_slice($_SERVER["argv"], 1);
            $args = str_replace("\n", "", $args);
        }

        // Split colons in the COMMAND NAME (first argument) to support
        // "namespace:command" style invocation (e.g. "make:migration").
        // Other arguments (options, parameter values) are left untouched so
        // values like "--file=/path:with:colons" are not corrupted.
        $expandedArgs = [];
        foreach ($args as $index => $arg) {
            if ($index === 0 && str_contains($arg, ':')) {
                $parts = explode(':', $arg);
                foreach ($parts as $part) {
                    if ($part !== '') {
                        $expandedArgs[] = $part;
                    }
                }
            } else {
                $expandedArgs[] = $arg;
            }
        }

        $args = $expandedArgs;

        if ((count($args) === 1 && $args[0] === "") || count($args) === 0 || (count($args) === 1 && $args[0] === "help")) {
            $commands = $this->consoleRouter->getRoutes()["CLI"];
            $output = "\nAvailable commands:\n\n";

            $maxLength = 0;
            foreach ($commands as $route => $command) {
                $maxLength = max($maxLength, strlen($route));
            }

            foreach ($commands as $route => $command) {
                $description = $command["description"] ?? '';
                $output .= "  \033[1m" . str_pad($route, $maxLength + 4) . "\033[0m";
                if ($description) {
                    $output .= $description;
                }
                $output .= "\n";
            }

            $output .= "\n";
            return $output;
        }

        $processedArgs = $this->processArguments($args);
        $commandArgs = $processedArgs['args'];
        $options = $processedArgs['options'];

        try {
            $response = $this->consoleRouter->analyseRouteAndLookup($commandArgs, CliRouter::$ROUTE_CLI);

            $reflect = new ReflectionClass($response["controller"]);
            $method = $reflect->getMethod($response["method"]);
            $controller = $reflect->newInstance();

            $varCount = count($response["variables"]);
            $filteredVariables = [];
            $variables = "";

            foreach ($method->getParameters() as $param) {
                if ($param->getName() == "options") {
                    $filteredVariables["options"] = $options;
                    continue;
                }

                $variables .= " [" . $param->getName() . "]";

                if (array_key_exists($param->getName(), $response["variables"])) {
                    $filteredVariables[$param->getName()] = $response["variables"][$param->getName()];
                    continue;
                }

                if (!$param->isDefaultValueAvailable()) {
                    return "Argument missing: The '" . $param->getName() . "' argument is required for this command.\nExpected format: [command] [argument_name]\nExample usage: " . $response["route"] . $variables;
                }
            }

            if ($varCount < $method->getNumberOfRequiredParameters() || count($method->getParameters()) < $varCount) {
                return "Insufficient arguments! The command requires at least " . $varCount . " parameters.\nUsage: " . $response["route"] . " " . $variables;
            }

            if (CommandLine::isCaptured()) {
                ob_start();
                $result = $method->invokeArgs($controller, $filteredVariables);
                $output = ob_get_clean();

                if (is_string($result) && $result !== '') {
                    $output .= $result;
                }

                return $output;
            }

            $result = $method->invokeArgs($controller, $filteredVariables);

            if (is_string($result) && $result !== '') {
                echo $result;
            }

            return '';
        } catch (HttpException $e) {
            $commands = $this->consoleRouter->getRoutes()["CLI"] ?? [];
            $output = "Unrecognized command. Type '\033[1mphp cli\033[0m' to see available commands.\n";

            $suggestions = [];
            $fullInput = strtolower(implode(' ', $args));

            foreach ($commands as $route => $command) {
                $routeBase = preg_replace('/\s+/', ' ', trim(preg_replace('/\{[^}]+\}/', '', $route)));
                $routeBase = strtolower($routeBase);

                if (str_starts_with($routeBase, $fullInput)) {
                    $suggestions[$route] = 0;
                } else if (preg_match('/\b' . preg_quote($fullInput) . '/i', $routeBase)) {
                    $suggestions[$route] = 1;
                } else {
                    $distance = levenshtein($fullInput, $routeBase);
                    $maxDistance = max(2, strlen($fullInput) / 2);
                    if ($distance <= $maxDistance) {
                        $suggestions[$route] = $distance + 10;
                    }
                }
            }

            asort($suggestions);
            $suggestions = array_slice(array_keys($suggestions), 0, 3);

            if (!empty($suggestions)) {
                $output .= "Did you mean something similar?\n\n";
                foreach ($suggestions as $suggestion) {
                    $output .= "  \033[1m" . $suggestion . "\033[0m\n";
                }
                $output .= "\n";
            }

            return $output;
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    }
    /**
     * Register a command file
     *
     * @param string $commandFile Path to command file
     * @return void
     */
    public function loadCommands(string $commandFile): void
    {
        if (!str_starts_with($commandFile, FileSystem::rootPath())) {
            $commandFile = FileSystem::rootPath() . DIRECTORY_SEPARATOR
                . ltrim($commandFile, DIRECTORY_SEPARATOR);
        }
        $this->commands[] = $commandFile;
    }

    /**
     * Resets the application instance.
     *
     * Replaces the singleton with a fresh Application, which naturally
     * resets $booted (and all other state) to its default. Used by tests
     * to obtain a clean application between cases.
     *
     * @return void
     */
    public static function reset(): void
    {
        $loggers = self::$instance?->loggers ?? [];
        Application::$instance = new Application();

        if (!empty($loggers)) {
            Application::$instance->loggers = $loggers;
        }
    }

    /**
     * Processes command line arguments, separating regular arguments from options
     * Options are arguments that start with '--'
     * Options can also have values like --file=/test.php
     *
     * @param array $argv Command line arguments array
     * @return array Associative array with 'args' and 'options' keys
     */
    function processArguments(array $argv): array
    {
        $args = [];
        $options = [];

        // Skip the script name (first argument)
        for ($i = 0; $i < count($argv); $i++) {
            $arg = $argv[$i];

            // Check if it's an option (starts with --)
            if (str_starts_with($arg, '--')) {
                $option = substr($arg, 2); // Remove the '--'

                // Check if it has a value with '='
                if (str_contains($option, '=')) {
                    list($key, $value) = explode('=', $option, 2);
                    $options[$key] = $value;
                } else {
                    // Option without value
                    $options[$option] = true;
                }
            } else {
                // Regular argument
                $args[] = $arg;
            }
        }

        return [
            'args' => $args,
            'options' => $options
        ];
    }

    public function registerFallback(ResponseInterface $response): void
    {
        $this->fallbackResponse = $response;
    }

    private function requiresOptions(ReflectionMethod $method): bool
    {
        return array_any($method->getParameters(), fn($parameter) => $parameter->getName() === "options");
    }

    /**
     * Set multiple HTTP headers from a headers array (string[][]).
     *
     * @param array<string, string[]> $headers Headers where each value is an array of strings
     * @param bool $replace Whether to replace previous headers with the same name (default: true)
     * @return void
     */
    public function setHeaders(array $headers, bool $replace = true): void
    {
        foreach ($headers as $name => $values) {
            $safeName = str_replace(["\r", "\n"], '', $name);
            foreach ($values as $value) {
                $safeValue = str_replace(["\r", "\n"], '', $value);
                header("$safeName: $safeValue", $replace);
            }
        }
    }

    private function responseWithError(HttpStatus $status, ?\Throwable $throwable = null): ResponseInterface
    {
        // Check for registered error pages first
        if (isset($this->errorPageResponses[$status->value])) {
            return $this->errorPageResponses[$status->value];
        }

        if ($status === HttpStatus::NOT_FOUND) {
            $fallback = $this->fallbackResponse ?? null;
            if ($fallback) {
                return $fallback;
            }
        }

        $response = new Response();
        $response = $response->withJsonEnvelope([], $status->message(), false, $status->value);

        // Normalise boolean env strings: only "1", "true", "on" and "yes"
        // (case-insensitive) are truthy; everything else is falsy.
        $is_debug = filter_var(App::env("DEBUG", false), FILTER_VALIDATE_BOOL);

        if (!$is_debug) {
            return $response;
        }

        $cause = $throwable instanceof HttpException ? $throwable->getPrevious() : $throwable;

        if ($cause !== null) {
            $debugPayload = [
                "message" => $cause->getMessage(),
                "code"    => $cause->getCode(),
                "file"    => $cause->getFile(),
                "line"    => $cause->getLine(),
                "trace"   => $cause->getTrace(),
            ];
            $response = $response->withJsonEnvelope(
                [],
                $status->message(),
                false,
                $status->value,
                ['exception' => $debugPayload]
            );
        }

        return $response;
    }

    public function registerErrorTemplate(int $code, ResponseInterface $response): void
    {
        $this->errorPageResponses[$code] = $response;
    }

    public function registerGlobalMiddleware(MiddlewareInterface|string $middleware): void
    {
        $this->globalMiddlewares[] = $middleware;
    }
}
