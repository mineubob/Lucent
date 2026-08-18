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
        $this->assertFalse($container->has(UnboundInterface::class));
    }

    public function test_has_returns_true_for_autowirable_concrete(): void
    {
        $container = new Container();
        $this->assertTrue($container->has(ContainerServiceStub::class));
    }

    public function test_get_throws_not_found_for_unregistered_entry(): void
    {
        $container = new Container();
        $exception = null;

        try {
            $container->get(UnboundInterface::class);
        } catch (NotFoundExceptionInterface $e) {
            $exception = $e;
        }

        $this->assertInstanceOf(NotFoundException::class, $exception);
        $this->assertSame(UnboundInterface::class, $exception->id);
    }

    public function test_get_autowires_unregistered_concrete(): void
    {
        $container = new Container();

        $this->assertInstanceOf(ContainerServiceStub::class, $container->get(ContainerServiceStub::class));
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

        $this->assertFalse($container->bound(ContainerServiceStub::class));
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

        $this->assertFalse($container->bound(ContainerServiceStub::class));
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

        $this->assertFalse($container->bound(ContainerServiceStub::class));
        $this->assertFalse($container->has('stub'), 'Aliases pointing to the removed id should be removed too');
    }

    public function test_remove_resolves_alias_to_underlying_entry(): void
    {
        $container = new Container();

        $container->singleton(ContainerServiceStub::class);
        $container->alias(ContainerServiceStub::class, 'stub');

        // Passing the alias removes the underlying entry and its aliases.
        $container->remove('stub');

        $this->assertFalse($container->bound(ContainerServiceStub::class));
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

    public function test_make_autowires_constructor_dependencies(): void
    {
        $container = new Container();

        $service = $container->make(NeedsDependencyStub::class);

        $this->assertInstanceOf(NeedsDependencyStub::class, $service);
        $this->assertInstanceOf(ContainerServiceStub::class, $service->dependency);
    }

    public function test_make_returns_fresh_instance_each_call(): void
    {
        $container = new Container();

        $a = $container->make(ContainerServiceStub::class);
        $b = $container->make(ContainerServiceStub::class);

        $this->assertNotSame($a, $b);
    }

    public function test_make_with_explicit_parameters(): void
    {
        $container = new Container();

        $service = $container->make(NeedsPrimitiveStub::class, ['value' => 42]);

        $this->assertSame(42, $service->value);
    }

    public function test_make_throws_for_unbound_interface(): void
    {
        $container = new Container();

        $this->expectException(NotFoundException::class);

        $container->make(UnboundInterface::class);
    }

    public function test_call_resolves_typed_parameters_from_container(): void
    {
        $container = new Container();

        $result = $container->call(fn (ContainerServiceStub $stub) => $stub::class);

        $this->assertSame(ContainerServiceStub::class, $result);
    }

    public function test_call_resolves_parameters_by_name(): void
    {
        $container = new Container();

        $result = $container->call(fn (int $id) => $id, ['id' => 7]);

        $this->assertSame(7, $result);
    }

    public function test_call_casts_primitive_string_to_int(): void
    {
        $container = new Container();

        $result = $container->call(fn (int $id) => $id, ['id' => '42']);

        $this->assertSame(42, $result);
    }

    public function test_call_instantiates_class_handler_via_container(): void
    {
        $container = new Container();

        $result = $container->call([NeedsDependencyStub::class, 'describe']);

        $this->assertSame(ContainerServiceStub::class, $result);
    }

    public function test_call_with_class_at_method_string(): void
    {
        $container = new Container();

        $result = $container->call(NeedsDependencyStub::class . '@describe');

        $this->assertSame(ContainerServiceStub::class, $result);
    }

    public function test_call_with_invokable_class_string(): void
    {
        $container = new Container();

        $result = $container->call(InvokableStub::class);

        $this->assertSame('invoked', $result);
    }

    public function test_call_uses_default_value_when_not_provided(): void
    {
        $container = new Container();

        $result = $container->call(fn (int $value = 5) => $value);

        $this->assertSame(5, $result);
    }

    public function test_call_throws_when_parameter_unresolvable(): void
    {
        $container = new Container();

        $this->expectException(ContainerException::class);

        $container->call(fn (UnboundInterface $dep) => $dep);
    }

    public function test_bindIf_does_not_override_existing_binding(): void
    {
        $container = new Container();
        $container->instance('svc', new ContainerServiceStub());

        $container->bindIf('svc', fn () => new ContainerServiceStub());

        $this->assertSame($container->get('svc'), $container->get('svc'));
    }

    public function test_scoped_is_flushed_on_flush(): void
    {
        $container = new Container();
        $container->scoped(ContainerServiceStub::class);

        $first = $container->get(ContainerServiceStub::class);
        $container->flush();
        $second = $container->get(ContainerServiceStub::class);

        $this->assertNotSame($first, $second);
    }

    public function test_extend_decorates_resolved_instance(): void
    {
        $container = new Container();
        $container->singleton(ContainerServiceStub::class);

        $container->extend(ContainerServiceStub::class, fn ($instance) => new DecoratedStub($instance));

        $this->assertInstanceOf(DecoratedStub::class, $container->get(ContainerServiceStub::class));
    }

    public function test_tag_and_tagged_resolve_all_abstracts(): void
    {
        $container = new Container();
        $container->singleton('first', ContainerServiceStub::class);
        $container->singleton('second', ContainerServiceStub::class);

        $container->tag(['first', 'second'], ['services']);

        $this->assertCount(2, $container->tagged('services'));
    }

    public function test_contextual_binding_overrides_dependency(): void
    {
        $container = new Container();
        $container->singleton(ContainerServiceStub::class);

        $container->when(NeedsDependencyStub::class)
            ->needs(ContainerServiceStub::class)
            ->give(AlternativeStub::class);

        $service = $container->make(NeedsDependencyStub::class);

        $this->assertInstanceOf(AlternativeStub::class, $service->dependency);
    }

    public function test_resolving_callback_fires_before_return(): void
    {
        $container = new Container();
        $fired = false;

        $container->resolving(ContainerServiceStub::class, function ($instance) use (&$fired) {
            $fired = true;
        });

        $container->make(ContainerServiceStub::class);

        $this->assertTrue($fired);
    }

    public function test_afterResolving_callback_fires_after_resolve(): void
    {
        $container = new Container();
        $fired = false;

        $container->afterResolving(ContainerServiceStub::class, function ($instance) use (&$fired) {
            $fired = true;
        });

        $container->make(ContainerServiceStub::class);

        $this->assertTrue($fired);
    }

    public function test_rebinding_callback_fires_on_rebind(): void
    {
        $container = new Container();
        $fired = false;

        $container->singleton(ContainerServiceStub::class);
        $container->get(ContainerServiceStub::class);

        $container->rebinding(ContainerServiceStub::class, function () use (&$fired) {
            $fired = true;
        });

        $container->instance(ContainerServiceStub::class, new ContainerServiceStub());

        $this->assertTrue($fired);
    }

    public function test_bound_returns_true_for_explicit_registration(): void
    {
        $container = new Container();
        $container->singleton(ContainerServiceStub::class);

        $this->assertTrue($container->bound(ContainerServiceStub::class));
        $this->assertFalse($container->bound(UnboundInterface::class));
    }

    public function test_resolved_tracks_resolution(): void
    {
        $container = new Container();
        $container->singleton(ContainerServiceStub::class);

        $this->assertFalse($container->resolved(ContainerServiceStub::class));

        $container->get(ContainerServiceStub::class);

        $this->assertTrue($container->resolved(ContainerServiceStub::class));
    }

    public function test_forgetInstance_clears_singleton(): void
    {
        $container = new Container();
        $container->singleton(ContainerServiceStub::class);

        $first = $container->get(ContainerServiceStub::class);
        $container->forgetInstance(ContainerServiceStub::class);
        $second = $container->get(ContainerServiceStub::class);

        $this->assertNotSame($first, $second);
    }
}

/**
 * A service with a constructor dependency on ContainerServiceStub.
 */
class NeedsDependencyStub
{
    public ContainerServiceStub $dependency;

    public function __construct(ContainerServiceStub $dependency)
    {
        $this->dependency = $dependency;
    }

    public function describe(): string
    {
        return $this->dependency::class;
    }
}

/**
 * A service with a primitive constructor parameter.
 */
class NeedsPrimitiveStub
{
    public int $value;

    public function __construct(int $value)
    {
        $this->value = $value;
    }
}

/**
 * An invokable service.
 */
class InvokableStub
{
    public function __invoke(): string
    {
        return 'invoked';
    }
}

/**
 * A decorator wrapping ContainerServiceStub.
 */
class DecoratedStub
{
    public function __construct(public ContainerServiceStub $inner)
    {
    }
}

/**
 * An alternative implementation of ContainerServiceStub's role.
 */
class AlternativeStub extends ContainerServiceStub
{
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

/**
 * An interface with no binding — used to assert NotFoundException.
 */
interface UnboundInterface
{
}
