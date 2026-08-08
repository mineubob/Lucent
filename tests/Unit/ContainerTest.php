<?php

namespace Tests\Unit;

use Lucent\Container\Container;
use Lucent\Container\ContainerException;
use Lucent\Container\NotFoundException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

class ContainerTest extends TestCase
{
    public function test_implements_psr11_container_interface(): void
    {
        $this->assertInstanceOf(ContainerInterface::class, new Container());
    }

    public function test_has_returns_false_for_unregistered_entry(): void
    {
        $container = new Container();
        $this->assertFalse($container->has(ContainerServiceStub::class));
    }

    public function test_get_throws_not_found_for_unregistered_entry(): void
    {
        $container = new Container();
        $exception = null;

        try {
            $container->get(ContainerServiceStub::class);
        } catch (NotFoundExceptionInterface $e) {
            $exception = $e;
        }

        $this->assertInstanceOf(NotFoundException::class, $exception);
        $this->assertSame(ContainerServiceStub::class, $exception->id);
    }

    public function test_instance_registers_by_class_name_when_no_alias(): void
    {
        $container = new Container();
        $service = new ContainerServiceStub();

        $container->instance($service);

        $this->assertTrue($container->has(ContainerServiceStub::class));
        $this->assertSame($service, $container->get(ContainerServiceStub::class));
    }

    public function test_instance_registers_under_alias(): void
    {
        $container = new Container();
        $service = new ContainerServiceStub();

        $container->instance($service, 'custom-alias');

        $this->assertFalse($container->has(ContainerServiceStub::class));
        $this->assertTrue($container->has('custom-alias'));
        $this->assertSame($service, $container->get('custom-alias'));
    }

    public function test_singleton_with_class_string_is_eager_and_shared(): void
    {
        $container = new Container();

        $container->singleton(ContainerServiceStub::class);

        $this->assertTrue($container->has(ContainerServiceStub::class));
        $this->assertInstanceOf(ContainerServiceStub::class, $container->get(ContainerServiceStub::class));
        $this->assertSame($container->get(ContainerServiceStub::class), $container->get(ContainerServiceStub::class));
    }

    public function test_singleton_with_class_string_under_alias(): void
    {
        $container = new Container();

        $container->singleton(ContainerServiceStub::class, 'stub');

        $this->assertFalse($container->has(ContainerServiceStub::class));
        $this->assertTrue($container->has('stub'));
        $this->assertInstanceOf(ContainerServiceStub::class, $container->get('stub'));
    }

    public function test_singleton_with_closure_is_lazy_and_cached(): void
    {
        $container = new Container();
        $calls = 0;

        $factory = function () use (&$calls) {
            $calls++;
            return new ContainerServiceStub();
        };

        $alias = 'lazy-service';
        $container->singleton($factory, $alias);

        // Factory should not have run until first get().
        $this->assertSame(0, $calls);
        $this->assertTrue($container->has($alias));

        $first = $container->get($alias);
        $second = $container->get($alias);

        $this->assertSame($first, $second, 'Lazy singleton should be cached and shared');
        $this->assertSame(1, $calls, 'Factory should have been invoked exactly once');
        $this->assertInstanceOf(ContainerServiceStub::class, $first);
    }

    public function test_singleton_with_closure_requires_an_alias(): void
    {
        $container = new Container();

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('must be registered under an explicit alias');

        $container->singleton(fn () => new ContainerServiceStub());
    }

    public function test_bind_with_closure_requires_an_alias(): void
    {
        $container = new Container();

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('must be registered under an explicit alias');

        $container->bind(fn () => new ContainerServiceStub());
    }

    public function test_bind_with_closure_returns_fresh_instance_each_get(): void
    {
        $container = new Container();
        $calls = 0;

        $container->bind(function () use (&$calls) {
            $calls++;
            return new ContainerServiceStub();
        }, 'factory');

        $this->assertTrue($container->has('factory'));

        $first = $container->get('factory');
        $second = $container->get('factory');

        $this->assertNotSame($first, $second, 'A bound factory should return a new instance each time');
        $this->assertSame(2, $calls);
    }

    public function test_closure_that_returns_non_object_raises_container_exception(): void
    {
        $container = new Container();

        $container->singleton(fn () => 'not-an-object', 'bad');

        $exception = null;
        try {
            $container->get('bad');
        } catch (ContainerExceptionInterface $e) {
            $exception = $e;
        }

        $this->assertInstanceOf(ContainerException::class, $exception);
    }

    public function test_get_is_reusable_after_not_found_for_different_ids(): void
    {
        $container = new Container();
        $service = new ContainerServiceStub();
        $container->instance($service, 'known');

        $this->assertTrue($container->has('known'));
        $this->assertSame($service, $container->get('known'));
    }

    public function test_instance_overrides_existing_binding(): void
    {
        $container = new Container();
        $container->bind(fn () => new ContainerServiceStub(), 'svc');

        $concrete = new ContainerServiceStub();
        $container->instance($concrete, 'svc');

        $this->assertSame($concrete, $container->get('svc'));
    }

    public function test_bind_overrides_existing_instance(): void
    {
        $container = new Container();
        $container->instance(new ContainerServiceStub(), 'svc');

        $container->bind(fn () => new ContainerServiceStub(), 'svc');

        $this->assertNotSame(
            $container->get('svc'),
            $container->get('svc'),
            'A later bind() should replace a previously registered instance'
        );
    }

    public function test_singleton_overrides_existing_binding(): void
    {
        $container = new Container();
        $container->bind(fn () => new ContainerServiceStub(), 'svc');

        $container->singleton(ContainerServiceStub::class, 'svc');

        $this->assertInstanceOf(ContainerServiceStub::class, $container->get('svc'));
        $this->assertSame($container->get('svc'), $container->get('svc'));
    }
}

/**
 * A trivial concrete service used to exercise the container.
 */
class ContainerServiceStub
{
}
