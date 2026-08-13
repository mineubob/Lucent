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

        $container->instance(ContainerServiceStub::class, $service);

        $this->assertTrue($container->has(ContainerServiceStub::class));
        $this->assertSame($service, $container->get(ContainerServiceStub::class));
    }

    public function test_instance_registers_under_alias(): void
    {
        $container = new Container();
        $service = new ContainerServiceStub();

        $container->instance('custom-alias', $service);

        $this->assertFalse($container->has(ContainerServiceStub::class));
        $this->assertTrue($container->has('custom-alias'));
        $this->assertSame($service, $container->get('custom-alias'));
    }

    public function test_instance_with_object_abstract_keys_by_class_name(): void
    {
        $container = new Container();
        $service = new ContainerServiceStub();

        $container->instance($service);

        $this->assertTrue($container->has(ContainerServiceStub::class));
        $this->assertSame($service, $container->get(ContainerServiceStub::class));
    }

    public function test_instance_with_string_abstract_requires_an_instance(): void
    {
        $container = new Container();

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('requires an instance when given a string identifier');

        $container->instance(ContainerServiceStub::class);
    }

    public function test_singleton_with_class_string_is_lazy_and_shared(): void
    {
        $container = new Container();

        $container->singleton(ContainerServiceStub::class);

        $this->assertTrue($container->has(ContainerServiceStub::class));
        $this->assertInstanceOf(ContainerServiceStub::class, $container->get(ContainerServiceStub::class));
        $this->assertSame($container->get(ContainerServiceStub::class), $container->get(ContainerServiceStub::class));
    }

    public function test_singleton_with_class_string_is_not_instantiated_until_get(): void
    {
        $container = new Container();
        ContainerServiceStub::$instances = 0;

        $container->singleton(ContainerServiceStub::class);

        // A lazy singleton must not be instantiated until the first get().
        $this->assertSame(0, ContainerServiceStub::$instances);
        $this->assertTrue($container->has(ContainerServiceStub::class));

        $container->get(ContainerServiceStub::class);
        $this->assertSame(1, ContainerServiceStub::$instances);
    }

    public function test_singleton_with_class_string_under_alias(): void
    {
        $container = new Container();

        $container->singleton('stub', ContainerServiceStub::class);

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
        $container->singleton($alias, $factory);

        // Factory should not have run until first get().
        $this->assertSame(0, $calls);
        $this->assertTrue($container->has($alias));

        $first = $container->get($alias);
        $second = $container->get($alias);

        $this->assertSame($first, $second, 'Lazy singleton should be cached and shared');
        $this->assertSame(1, $calls, 'Factory should have been invoked exactly once');
        $this->assertInstanceOf(ContainerServiceStub::class, $first);
    }

    public function test_singleton_with_callable_abstract_keys_by_return_type(): void
    {
        $container = new Container();
        $calls = 0;

        $container->singleton(function () use (&$calls): ContainerServiceStub {
            $calls++;
            return new ContainerServiceStub();
        });

        // Identifier derived from the return type; factory is lazy.
        $this->assertSame(0, $calls);
        $this->assertTrue($container->has(ContainerServiceStub::class));

        $first = $container->get(ContainerServiceStub::class);
        $second = $container->get(ContainerServiceStub::class);

        $this->assertSame($first, $second);
        $this->assertSame(1, $calls);
    }

    public function test_singleton_with_callable_abstract_without_return_type_throws(): void
    {
        $container = new Container();

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('must declare a class return type');

        $container->singleton(fn () => new ContainerServiceStub());
    }

    public function test_singleton_with_closure_under_class_abstract(): void
    {
        $container = new Container();
        $calls = 0;

        $container->singleton(ContainerServiceStub::class, function () use (&$calls) {
            $calls++;
            return new ContainerServiceStub();
        });

        $this->assertSame(0, $calls);
        $this->assertTrue($container->has(ContainerServiceStub::class));

        $first = $container->get(ContainerServiceStub::class);
        $second = $container->get(ContainerServiceStub::class);

        $this->assertSame($first, $second);
        $this->assertSame(1, $calls);
    }

    public function test_bind_with_closure_returns_fresh_instance_each_get(): void
    {
        $container = new Container();
        $calls = 0;

        $container->bind('factory', function () use (&$calls) {
            $calls++;
            return new ContainerServiceStub();
        });

        $this->assertTrue($container->has('factory'));

        $first = $container->get('factory');
        $second = $container->get('factory');

        $this->assertNotSame($first, $second, 'A bound factory should return a new instance each time');
        $this->assertSame(2, $calls);
    }

    public function test_bind_with_callable_abstract_keys_by_return_type(): void
    {
        $container = new Container();
        $calls = 0;

        $container->bind(function () use (&$calls): ContainerServiceStub {
            $calls++;
            return new ContainerServiceStub();
        });

        $this->assertTrue($container->has(ContainerServiceStub::class));

        $first = $container->get(ContainerServiceStub::class);
        $second = $container->get(ContainerServiceStub::class);

        $this->assertNotSame($first, $second, 'A bound callable abstract should return a new instance each time');
        $this->assertSame(2, $calls);
    }

    public function test_closure_that_returns_non_object_raises_container_exception(): void
    {
        $container = new Container();

        $container->singleton('bad', fn () => 'not-an-object');

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
        $container->instance('known', $service);

        $this->assertTrue($container->has('known'));
        $this->assertSame($service, $container->get('known'));
    }

    public function test_instance_overrides_existing_binding(): void
    {
        $container = new Container();
        $container->bind('svc', fn () => new ContainerServiceStub());

        $concrete = new ContainerServiceStub();
        $container->instance('svc', $concrete);

        $this->assertSame($concrete, $container->get('svc'));
    }

    public function test_bind_overrides_existing_instance(): void
    {
        $container = new Container();
        $container->instance('svc', new ContainerServiceStub());

        $container->bind('svc', fn () => new ContainerServiceStub());

        $this->assertNotSame(
            $container->get('svc'),
            $container->get('svc'),
            'A later bind() should replace a previously registered instance'
        );
    }

    public function test_singleton_overrides_existing_binding(): void
    {
        $container = new Container();
        $container->bind('svc', fn () => new ContainerServiceStub());

        $container->singleton('svc', ContainerServiceStub::class);

        $this->assertInstanceOf(ContainerServiceStub::class, $container->get('svc'));
        $this->assertSame($container->get('svc'), $container->get('svc'));
    }

    public function test_alias_resolves_to_same_singleton_instance(): void
    {
        $container = new Container();

        $container->singleton(ContainerServiceStub::class);
        $container->alias(ContainerServiceStub::class, 'stub');

        $this->assertTrue($container->has('stub'));
        $this->assertSame(
            $container->get(ContainerServiceStub::class),
            $container->get('stub'),
            'An alias should resolve to the same shared instance'
        );
    }

    public function test_alias_can_be_registered_before_abstract(): void
    {
        $container = new Container();

        $container->alias(ContainerServiceStub::class, 'stub');
        $container->singleton(ContainerServiceStub::class);

        $this->assertTrue($container->has('stub'));
        $this->assertSame(
            $container->get(ContainerServiceStub::class),
            $container->get('stub')
        );
    }

    public function test_alias_chains_resolve_to_terminal_abstract(): void
    {
        $container = new Container();

        $container->singleton(ContainerServiceStub::class);
        $container->alias(ContainerServiceStub::class, 'first');
        $container->alias('first', 'second');

        $this->assertSame(
            $container->get(ContainerServiceStub::class),
            $container->get('second')
        );
    }

    public function test_alias_does_not_instantiate_until_get(): void
    {
        $container = new Container();
        ContainerServiceStub::$instances = 0;

        $container->singleton(ContainerServiceStub::class);
        $container->alias(ContainerServiceStub::class, 'stub');

        $this->assertSame(0, ContainerServiceStub::$instances);
        $container->get('stub');
        $this->assertSame(1, ContainerServiceStub::$instances);
    }

    public function test_remove_clears_entry_and_aliases_pointing_to_it(): void
    {
        $container = new Container();

        $container->singleton(ContainerServiceStub::class);
        $container->alias(ContainerServiceStub::class, 'stub');

        $container->remove(ContainerServiceStub::class);

        $this->assertFalse($container->has(ContainerServiceStub::class));
        $this->assertFalse($container->has('stub'), 'Aliases pointing to the removed id should be removed too');
    }

    public function test_remove_resolves_alias_to_underlying_entry(): void
    {
        $container = new Container();

        $container->singleton(ContainerServiceStub::class);
        $container->alias(ContainerServiceStub::class, 'stub');

        // Passing the alias removes the underlying entry and its aliases.
        $container->remove('stub');

        $this->assertFalse($container->has(ContainerServiceStub::class));
        $this->assertFalse($container->has('stub'));
    }

    public function test_remove_alias_removes_only_the_alias(): void
    {
        $container = new Container();

        $container->singleton(ContainerServiceStub::class);
        $container->alias(ContainerServiceStub::class, 'stub');

        $container->removeAlias('stub');

        $this->assertFalse($container->has('stub'));
        $this->assertTrue($container->has(ContainerServiceStub::class), 'The entry should remain intact');
    }

    public function test_remove_clears_transitive_alias_chain(): void
    {
        $container = new Container();

        $container->singleton(ContainerServiceStub::class);
        $container->alias(ContainerServiceStub::class, 'first');
        $container->alias('first', 'second');

        $container->remove(ContainerServiceStub::class);

        $this->assertFalse($container->has('first'));
        $this->assertFalse($container->has('second'));
    }

    public function test_remove_preserves_unrelated_aliases(): void
    {
        $container = new Container();

        $container->singleton(ContainerServiceStub::class);
        $container->alias(ContainerServiceStub::class, 'stub');
        $container->singleton('other', ContainerServiceStub::class);
        $container->alias('other', 'other-alias');

        $container->remove(ContainerServiceStub::class);

        $this->assertFalse($container->has('stub'));
        $this->assertTrue($container->has('other-alias'), 'Unrelated aliases should be preserved');
        $this->assertTrue($container->has('other'));
    }

    public function test_remove_does_not_break_alias_before_abstract_re_registration(): void
    {
        $container = new Container();

        // Alias registered before the abstract survives re-registration.
        $container->alias(ContainerServiceStub::class, 'stub');
        $container->singleton(ContainerServiceStub::class);

        $this->assertTrue($container->has('stub'));

        // Re-registering (via singleton) must not wipe the alias.
        $container->singleton(ContainerServiceStub::class);
        $this->assertTrue($container->has('stub'));
    }
}

/**
 * A trivial concrete service used to exercise the container.
 */
class ContainerServiceStub
{
    /** @var int Number of times this stub has been instantiated. */
    public static int $instances = 0;

    public function __construct()
    {
        self::$instances++;
    }
}
