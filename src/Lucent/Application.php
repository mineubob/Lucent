<?php

namespace Lucent;

use InvalidArgumentException;
use Lucent\Cache\Cache;
use Lucent\Cache\CacheFactory;
use Lucent\Container\Container;
use Lucent\Commandline\ClearCacheCommand;
use Lucent\Commandline\CliRouter;
use Lucent\Commandline\DeploymentController;
use Lucent\Commandline\GenerateDocumentationCommand;
use Lucent\Commandline\PerformMigrationCommand;
use Lucent\Commandline\StartDevServerCommand;
use Lucent\Date\Clock;
use Lucent\EventDispatcher\EventDispatcher;
use Lucent\EventDispatcher\ListenerProvider;
use Lucent\Facades\App;
use Lucent\Facades\CommandLine;
use Lucent\Facades\FileSystem;
use Lucent\Facades\Log;
use Lucent\Http\Exceptions\HttpException;
use Lucent\Http\HttpRouter;
use Lucent\Http\HttpStatus;
use Lucent\Http\Message\Response;
use Lucent\Http\Message\ServerRequest;
use Lucent\Http\Middleware\CallbackRequestHandler;
use Lucent\Http\Middleware\MiddlewarePipeline;
use Lucent\Http\RouteInfo;
use Lucent\Logging\Channel;
use Lucent\Logging\Channels\NullChannel;
use Lucent\Model\Model;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface;
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
     * Dispatches events to their registered listeners.
     *
     * @var EventDispatcher
     */
    public private(set) EventDispatcher $eventDispatcher;

    /**
     * Maps events to the listeners registered for them.
     *
     * @var ListenerProvider
     */
    public private(set) ListenerProvider $listenerProvider;

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
     * The application's query cache store.
     *
     * A dedicated store, separate from the main cache, built lazily from the
     * `QUERY_CACHE_DRIVER` / `QUERY_CACHE_PATH` environment variables on
     * first access. Injected into {@see Database} as the query cache when
     * `QUERY_CACHE` is enabled.
     *
     * @var CacheInterface|null
     */
    private ?CacheInterface $queryCache = null;

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
     * An array of globally accessible regex rules.
     */
    public private(set) array $regexRules = [
        'password' => [
            "pattern" => '/^(?=.*[a-z])(?=.*[A-Z]).{8,}$/',
            "message" => "Password must contain at least one lowercase letter, one uppercase letter, and be at least 8 characters long.",
        ],
        'email' => [
            "pattern" => '/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/',
            "message" => "Email address must be a valid email address. (test@example.com)",
        ],
        'date' => [
            "pattern" => '/^\d{4}-\d{2}-\d{2}$/',
            "message" => "Date must be in YYYY-MM-DD format.",
        ],
        'url' => [
            "pattern" => '/^(https?:\/\/)?([\da-z\.-]+)\.([a-z\.]{2,6})([\/\w \.-]*)*\/?$/',
            "message" => "URL must be a valid web address.",
        ],
        'phone' => [
            "pattern" => '/^\+?[1-9]\d{1,14}$/',
            "message" => "Phone number must be in a valid international format.",
        ],
        'ip' => [
            "pattern" => '/^(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)$/',
            "message" => "Must be a valid IPv4 address.",
        ],
        'hex_color' => [
            "pattern" => '/^#?([a-fA-F0-9]{6}|[a-fA-F0-9]{3})$/',
            "message" => "Must be a valid HEX color code (e.g., #FFF or #FFFFFF).",
        ],
        'uuid' => [
            "pattern" => '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            "message" => "Must be a valid UUID.",
        ],
        'alpha' => [
            "pattern" => '/^[a-zA-Z]+$/',
            "message" => "Must contain only letters.",
        ],
        'alphanumeric' => [
            "pattern" => '/^[a-zA-Z0-9]+$/',
            "message" => "Must contain only letters and numbers.",
        ]
    ];

    /**
     * An array of globally accessible failed message errors.
     */
    private array $ruleMessages = [
        "min" => ":attribute must be at least :min characters",
        "max" => ":attribute may not be greater than :max characters",
        "min_num" => ":attribute must be greater than :min",
        "max_num" => ":attribute may not be less than :max",
        "same" => ":attribute and :second must match"
    ];

    /**
     * An array of globally applicable middleware thats ran for all requests.
     */
    private array $globalMiddlewares = [];

    /**
     * Initialize a new Application instance
     *
     * Sets up HTTP and CLI routers, ensures .env file exists,
     * loads environment variables, and initializes a null logger.
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

        // Register the shared PSR-20 clock so services can type-hint
        // Psr\Clock\ClockInterface for constructor injection.
        $this->container->instance(Clock::local(), ClockInterface::class);
        $this->container->instance(Clock::local(), Clock::class);

        // Set up the event dispatcher and its listener provider, and expose
        // them through the container so they can be resolved by interface.
        $this->listenerProvider = new ListenerProvider();
        $this->eventDispatcher = new EventDispatcher($this->listenerProvider, $this->container);

        $this->container->instance($this->listenerProvider, ListenerProviderInterface::class);
        $this->container->instance($this->eventDispatcher, EventDispatcherInterface::class);
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
     * Register a listener for an event.
     *
     * Convenience passthrough to the application's listener provider.
     *
     * @param class-string $eventClass Event class (or parent class / interface) to listen for
     * @param callable|string $listener Callable, or class-string of an invokable listener
     * @param int $priority Higher priorities run first; defaults to 0
     * @return void
     */
    public function listen(string $eventClass, callable|string $listener, int $priority = 0): void
    {
        $this->listenerProvider->listen($eventClass, $listener, $priority);
    }

    /**
     * Dispatch an event to its registered listeners.
     *
     * Convenience passthrough to the application's event dispatcher.
     *
     * @param object $event The event to dispatch
     * @return object The event, possibly modified by listeners
     */
    public function dispatch(object $event): object
    {
        return $this->eventDispatcher->dispatch($event);
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

            $this->container->instance($this->cache, CacheInterface::class);
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
        $this->container->instance($cache, CacheInterface::class);
    }

    /**
     * Get the application's query cache store.
     *
     * Builds a dedicated store lazily on first access from the
     * `QUERY_CACHE_DRIVER` environment variable (defaulting to `array`) and
     * `QUERY_CACHE_PATH` (defaulting to `storage/cache`). Kept separate from
     * the main cache so each can use a different driver. The same instance is
     * returned on subsequent calls.
     *
     * @return CacheInterface The query cache store
     */
    public function queryCache(): CacheInterface
    {
        if ($this->queryCache === null) {
            $driver = $this->env['QUERY_CACHE_DRIVER'] ?? 'array';
            $path = $this->env['QUERY_CACHE_PATH'] ?? 'storage/cache';

            $this->queryCache = CacheFactory::create($driver, $this->container, $path);
        }

        return $this->queryCache;
    }

    /**
     * Inject the application's query cache store into the database, when
     * query caching is enabled.
     *
     * Query caching is opt-in via the `QUERY_CACHE` environment variable. When
     * it is truthy, the dedicated query cache store is passed to
     * {@see Database::setQueryCache()} so SELECT results are cached. When it
     * is falsy, any previously injected query cache is cleared.
     *
     * @return void
     */
    private function injectQueryCache(): void
    {
        $enabled = filter_var($this->env['QUERY_CACHE'] ?? false, FILTER_VALIDATE_BOOL);

        Database::setQueryCache($enabled ? $this->queryCache() : null);
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

        Database::setLogger(Log::channel("lucent.db"));

        // Wire up the query cache from the environment (QUERY_CACHE) so it is
        // active before any queries run. The store itself stays lazy — it is
        // only built here when QUERY_CACHE is truthy.
        $this->injectQueryCache();
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
        $response = $this->handleHttpRequest(ServerRequest::fromGlobals());

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
            $fallback = new CallbackRequestHandler(function (ServerRequestInterface $request): ResponseInterface {
                try {
                    return $this->dispatchRoute($request);
                } catch (HttpException $e) {
                    Log::channel('lucent.routing')->warning($e->getMessage());
                    return $this->responseWithError($e->getStatus(), $e);
                } catch (Throwable $throwable) {
                    Log::channel('lucent.routing')->warning($throwable->getMessage());
                    return $this->responseWithError(HttpStatus::SERVER_ERROR, $throwable);
                }
            });

            $pipeline = new MiddlewarePipeline($middlewareList, $fallback);

            return $pipeline->handle($request);
        } catch (HttpException $e) {
            // An HttpException thrown by global middleware itself (e.g. a 401
            // auth middleware) keeps its status rather than becoming a 500.
            Log::channel('lucent.routing')->warning($e->getMessage());
            return $this->responseWithError($e->getStatus(), $e);
        } catch (Throwable $throwable) {
            // Exceptions thrown by global middleware itself still produce a
            // 500 response rather than escaping the request handler.
            Log::channel('lucent.routing')->warning($throwable->getMessage());
            return $this->responseWithError(HttpStatus::SERVER_ERROR, $throwable);
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

        $controllerReflection = new ReflectionClass($routeData["controller"]);
        $parameters = $this->resolveConstructorParameters($controllerReflection);

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

        $method = $controllerReflection->getMethod($routeData["method"]);

        // Build route-scoped middleware pipeline
        $middlewareList = [];
        foreach ($routeData["middleware"] as $middleware) {
            $middlewareList[] = $this->resolveMiddleware($middleware);
        }

        // Build controller dispatch callback
        $dispatchCallback = function (ServerRequestInterface $request) use (
            $controllerReflection,
            $method,
            $routeData,
            $parameters
        ): ResponseInterface {
            return $this->dispatchController($request, $controllerReflection, $method, $routeData, $parameters);
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
     * Resolve controller constructor parameters from the service container.
     *
     * @param ReflectionClass $controllerReflection Controller reflection
     * @return array<string, mixed> Parameter name => resolved value
     */
    private function resolveConstructorParameters(ReflectionClass $controllerReflection): array
    {
        $controllerConstructor = $controllerReflection->getConstructor();
        $parameters = [];

        if ($controllerConstructor !== null && $controllerConstructor->getNumberOfRequiredParameters() !== 0) {
            foreach ($controllerConstructor->getParameters() as $parameter) {
                $parameterType = $parameter->getType();

                if ($parameterType === null || !($parameterType instanceof ReflectionNamedType)) {
                    throw new InvalidArgumentException(
                        sprintf(
                            "Constructor parameter '%s' in controller '%s' must have a named type hint to be resolved from the service container.",
                            $parameter->getName(),
                            $controllerReflection->getName()
                        )
                    );
                }

                if ($this->container->has($parameterType->getName())) {
                    $parameters[$parameter->getName()] = $this->container->get($parameterType->getName());
                } else if ($parameter->isDefaultValueAvailable()) {
                    $parameters[$parameter->getName()] = $parameter->getDefaultValue();
                } else {
                    throw new InvalidArgumentException(
                        sprintf(
                            "No service registered for required constructor parameter '%s' of type '%s' in controller '%s'.",
                            $parameter->getName(),
                            $parameterType->getName(),
                            $controllerReflection->getName()
                        )
                    );
                }
            }
        }

        return $parameters;
    }

    /**
     * Dispatch a matched route to its controller method.
     *
     * Instantiates the controller, injects the PSR-7 request and services,
     * applies model binding for route parameters, invokes the method, and
     * validates the returned ResponseInterface.
     *
     * @param ServerRequestInterface $request The PSR-7 request
     * @param ReflectionClass $controllerReflection Controller reflection
     * @param ReflectionMethod $method Controller method to invoke
     * @param array $routeData Matched route data
     * @param array $parameters Resolved constructor parameters
     * @return ResponseInterface
     */
    private function dispatchController(
        ServerRequestInterface $request,
        ReflectionClass $controllerReflection,
        ReflectionMethod $method,
        array $routeData,
        array $parameters
    ): ResponseInterface {
        // Resolve controller
        if ($parameters !== []) {
            $controller = $controllerReflection->newInstanceArgs($parameters);
        } else {
            $controller = $controllerReflection->newInstance();
        }

        // Check if method requires a PSR-7 request parameter
        $psr7Injection = $this->requiresPsr7Request($method);

        $variables = $routeData["variables"];

        if ($psr7Injection !== null) {
            $variables[$psr7Injection] = $request;
        }

        // Apply model binding for route parameters
        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();
            $name = $parameter->getName();

            if ($type === null) {
                continue;
            }

            if (!($type instanceof ReflectionNamedType)) {
                throw new \InvalidArgumentException(
                    sprintf(
                        "Parameter '%s' in method '%s::%s()' must have a named type hint.",
                        $parameter->getName(),
                        $method->getDeclaringClass()->getName(),
                        $method->getName()
                    )
                );
            }

            $typeName = $type->getName();

            // Service Injection
            if ($this->container->has($typeName)) {
                $variables[$name] = $this->container->get($typeName);
                continue;
            }

            // Skip non-model types
            if (!is_subclass_of($typeName, Model::class)) {
                continue;
            }

            $reflection = new ReflectionClass($typeName);
            $pkValue = $variables[$name];
            $pkKey = $typeName::getDatabasePrimaryKey($reflection)->name;

            $context = $routeData["variables"];
            if (
                array_key_exists($name, $context)
                && $context[$name] instanceof $typeName
                && property_exists($context[$name], $pkKey)
                && $context[$name]->$pkKey == $pkValue
            ) {
                $instance = $context[$name];
            } else {
                $instance = $typeName::where($pkKey, $pkValue)->getFirst();
            }

            if ($instance === null) {
                throw new HttpException(HttpStatus::NOT_FOUND);
            }

            $variables[$name] = $instance;
        }

        $result = $method->invokeArgs($controller, $variables);

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
        Database::configure($this->env);
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
        Database::configure($this->env);
        $this->injectQueryCache();
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

        CommandLine::register(PerformMigrationCommand::$command, "make", PerformMigrationCommand::class, "Generates a database table from the model class.");
        CommandLine::register(GenerateDocumentationCommand::$command, "generateApi", GenerateDocumentationCommand::class, "Generates API documentation based on your controller attributes");
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

    public function addRegex(string $key, string $pattern, ?string $message = null): void
    {
        $this->regexRules[$key] = ["pattern" => $pattern, "message" => $message];
    }

    public function getRegexRules(): array
    {
        return $this->regexRules;
    }

    public function overrideValidationMessage(string $key, string $message): void
    {
        $this->ruleMessages[$key] = $message;
    }

    public function getValidationMessages(): array
    {
        return $this->ruleMessages;
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
