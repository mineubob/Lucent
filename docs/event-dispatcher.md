[Home](../README.md)

# Event Dispatcher

Lucent ships with an event dispatcher that lets you decouple parts of your
application by emitting events and reacting to them with listeners. It is
[PSR-14](https://www.php-fig.org/psr/psr-14/) compatible.

The dispatcher and its listener provider are scoped to the application
singleton and are accessible through the `Event` facade:

```php
use Lucent\Facades\Event;

Event::listen(UserCreated::class, function (UserCreated $event) {
    // react to the event
});

Event::dispatch(new UserCreated($user));
```

## Registering Listeners

Use `Event::listen()` to register a listener for an event class. The listener
may be a closure, an invokable object, or a class-string.

### Closure

```php
Event::listen(UserCreated::class, function (UserCreated $event) {
    Log::info("User {$event->user->id} created");
});
```

### Invokable Class

Pass a class-string to have the listener resolved through the container, so
its constructor dependencies are injected. The resolved instance must be
invokable (define `__invoke`):

```php
class SendWelcomeMail
{
    public function __construct(private Mailer $mailer) {}

    public function __invoke(UserCreated $event): void
    {
        $this->mailer->send($event->user->email, 'Welcome!');
    }
}

Event::listen(UserCreated::class, SendWelcomeMail::class);
```

### Priority

Listeners run in priority order — higher priorities run first. Listeners with
the same priority run in the order they were registered:

```php
Event::listen(UserCreated::class, $auditListener, 10);  // runs first
Event::listen(UserCreated::class, $mailListener, 0);    // runs second
```

## Dispatching Events

`Event::dispatch()` returns the same event instance, so listeners can mutate
it and later listeners (and the caller) observe the changes:

```php
$event = Event::dispatch(new OrderPlaced($order));
```

## Stoppable Events

Extend `Lucent\EventDispatcher\StoppableEvent` to create an event whose
propagation can be halted. A listener calls `stopPropagation()` to prevent any
remaining listeners from running:

```php
use Lucent\EventDispatcher\StoppableEvent;

class UserCreated extends StoppableEvent
{
    public function __construct(public readonly User $user) {}
}

Event::listen(UserCreated::class, function (UserCreated $event) {
    if ($event->user->isBanned()) {
        $event->stopPropagation();
    }
});
```

## Listening to Parent Classes and Interfaces

A listener registered for a parent class or interface receives events of any
subclass. This is useful for handling a family of related events together:

```php
Event::listen(OrderEvent::class, $orderAuditor); // receives OrderPlaced, OrderShipped, ...

Event::listen(StoppableEvent::class, $guard);    // receives any stoppable event
```

## Accessing the Dispatcher Directly

The dispatcher and provider are also resolvable from the container by their
interfaces:

```php
use Lucent\Facades\App;
use Psr\EventDispatcher\EventDispatcherInterface;

$dispatcher = App::container()->get(EventDispatcherInterface::class);
```