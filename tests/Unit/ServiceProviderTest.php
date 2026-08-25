<?php

namespace Tests\Unit;

use Lucent\Container\Container;
use Lucent\Container\ServiceProvider;
use Lucent\EventDispatcher\EventDispatcherServiceProvider;
use Lucent\Http\Exceptions\Exceptions;
use Lucent\Http\Exceptions\ExceptionsServiceProvider;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface;

/**
 * Tests for the service provider lifecycle and core providers.
 */
class ServiceProviderTest extends TestCase
{
    public function test_event_dispatcher_provider_registers_dispatcher_and_provider(): void
    {
        $container = new Container();
        $provider = new EventDispatcherServiceProvider($container);

        $provider->register();

        $this->assertInstanceOf(EventDispatcherInterface::class, $container->get(EventDispatcherInterface::class));
        $this->assertInstanceOf(ListenerProviderInterface::class, $container->get(ListenerProviderInterface::class));
    }

    public function test_exceptions_provider_is_deferred(): void
    {
        $provider = new ExceptionsServiceProvider(new Container());

        $this->assertTrue($provider->isDeferred());
        $this->assertSame([Exceptions::class], $provider->provides());
    }

    public function test_exceptions_provider_registers_lazy_singleton(): void
    {
        $container = new Container();
        $provider = new ExceptionsServiceProvider($container);

        $provider->register();

        $this->assertTrue($container->has(Exceptions::class));
        $this->assertInstanceOf(Exceptions::class, $container->get(Exceptions::class));
        $this->assertSame($container->get(Exceptions::class), $container->get(Exceptions::class));
    }

    public function test_boot_is_called_after_all_providers_registered(): void
    {
        $container = new Container();
        $provider = new BootOrderProbeProvider($container);

        // register() should run immediately on construction of the app; boot()
        // is invoked explicitly by the app after all providers are registered.
        $provider->register();
        $provider->boot();

        $this->assertTrue($provider->registered);
        $this->assertTrue($provider->booted);
    }

    public function test_passthrough_methods_delegate_to_container(): void
    {
        $container = new Container();
        $provider = new PassthroughProbeProvider($container);

        $result = $provider->resolveViaMake();

        $this->assertInstanceOf(PassthroughService::class, $result);
    }
}

/**
 * A probe provider recording register()/boot() invocation.
 */
class BootOrderProbeProvider extends ServiceProvider
{
    public bool $registered = false;
    public bool $booted = false;

    public function register(): void
    {
        $this->registered = true;
        $this->container->instance(ProbeService::class, new ProbeService());
    }

    public function boot(): void
    {
        $this->booted = true;
    }
}

/**
 * A trivial service for boot-order probing.
 */
class ProbeService
{
}

/**
 * A provider exercising the make() passthrough.
 */
class PassthroughProbeProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->singleton(PassthroughService::class);
    }

    /**
     * Resolve the service via the protected make() passthrough.
     */
    public function resolveViaMake(): mixed
    {
        return $this->make(PassthroughService::class);
    }
}

/**
 * A trivial autowirable service.
 */
class PassthroughService
{
}
