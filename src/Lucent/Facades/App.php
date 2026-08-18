<?php

/**
 * Copyright Jack Harris
 * Peninsula Interactive - nextstats-auth
 * Last Updated - 7/11/2023
 */

namespace Lucent\Facades;


use Lucent\Application;
use Lucent\Container\Container;
use Lucent\Database;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;

class App
{
    public static function env(string $key, $default = null)
    {

        $env = Application::getInstance()->getEnv();

        if (array_key_exists($key, $env)) {
            return trim($env[$key]);
        } else {
            return $default;
        }
    }

    public static function getLucentVersion(): string
    {
        return VERSION;
    }


    public static function registerRoutes(string $routeFile): void
    {
        Application::getInstance()->loadRoutes($routeFile);
    }

    public static function registerCommands(string $commandFile): void
    {
        Application::getInstance()->loadCommands($commandFile);
    }

    public static function registerDatabaseDriver(string $key, string $driverClass): void
    {
        Database::registerDatabaseDriver($key, $driverClass);
    }

    public static function registerGlobalMiddlewares(MiddlewareInterface|string $middleware): void
    {
        Application::getInstance()->registerGlobalMiddleware($middleware);
    }

    public static function handleHttpRequest(ServerRequestInterface $request): ResponseInterface
    {
        return Application::getInstance()->handleHttpRequest($request);
    }

    public static function execute(): string
    {
        return Application::getInstance()->executeHttpRequest();
    }

    /**
     * Get the application's dependency injection container.
     *
     * The container is scoped to the {@see Application} singleton, so this
     * always returns the same instance for the lifetime of the application.
     *
     * ```php
     * $container = App::container();
     * $container->singleton(MyService::class);
     * $service = $container->get(MyService::class);
     * ```
     *
     * @return Container The PSR-11 service container
     */
    public static function container(): Container
    {
        return Application::getInstance()->container();
    }

    /**
     * Resolve a service from the container, autowiring its dependencies.
     *
     * ```php
     * $service = App::make(MyService::class);
     * ```
     *
     * @param string $abstract Identifier (class name or alias) to resolve
     * @param array $parameters Explicit values keyed by constructor parameter name
     * @return mixed The resolved entry
     */
    public static function make(string $abstract, array $parameters = []): mixed
    {
        return Application::getInstance()->make($abstract, $parameters);
    }

    /**
     * Invoke a callable, resolving its parameters from the container.
     *
     * ```php
     * $result = App::call([$controller, 'show'], ['id' => 5]);
     * ```
     *
     * @param callable|string|array $callback The callable to invoke
     * @param array $parameters Explicit values keyed by parameter name
     * @param string|null $defaultMethod Method to invoke when $callback is an invokable class string
     * @return mixed The callable's return value
     */
    public static function call(callable|string|array $callback, array $parameters = [], ?string $defaultMethod = null): mixed
    {
        return Application::getInstance()->call($callback, $parameters, $defaultMethod);
    }

    /**
     * Get the shared exception manager.
     *
     * ```php
     * App::exceptions()->render(function (HttpException $e) {
     *     // Return a Response for any HttpException, or null to fall through.
     *     return null;
     * });
     * ```
     *
     * @return \Lucent\Http\Exceptions\Exceptions The shared exception manager
     */
    public static function exceptions(): \Lucent\Http\Exceptions\Exceptions
    {
        return Application::getInstance()->exceptions();
    }
}
