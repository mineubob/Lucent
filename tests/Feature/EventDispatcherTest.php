<?php

namespace Tests\Feature;

use Lucent\Application;
use Lucent\EventDispatcher\EventDispatcher;
use Lucent\EventDispatcher\ListenerProvider;
use Lucent\Facades\Event;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface;
use Tests\Support\Concerns\RefreshApplication;
use Tests\Support\TestCase;

class EventDispatcherTest extends TestCase
{
    use RefreshApplication;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::refreshApplication();
    }

    public function test_application_exposes_dispatcher_and_provider(): void
    {
        $container = Application::getInstance()->container();

        $this->assertInstanceOf(EventDispatcher::class, $container->get(EventDispatcherInterface::class));
        $this->assertInstanceOf(ListenerProvider::class, $container->get(ListenerProviderInterface::class));
    }

    public function test_dispatcher_and_provider_are_resolvable_from_container(): void
    {
        $container = Application::getInstance()->container();

        $this->assertInstanceOf(
            EventDispatcherInterface::class,
            $container->get(EventDispatcherInterface::class),
        );
        $this->assertInstanceOf(
            ListenerProviderInterface::class,
            $container->get(ListenerProviderInterface::class),
        );
    }

    public function test_facade_listen_and_dispatch_work_end_to_end(): void
    {
        $seen = null;

        Event::listen(FeatureEvent::class, function (FeatureEvent $event) use (&$seen): void {
            $seen = $event->value;
        });

        $event = new FeatureEvent('hello');
        $result = Event::dispatch($event);

        $this->assertSame($event, $result);
        $this->assertSame('hello', $seen);
    }
}

class FeatureEvent
{
    public function __construct(public readonly string $value)
    {
    }
}