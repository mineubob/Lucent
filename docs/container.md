[Home](../README.md)

# Dependency Injection Container

Lucent ships with a [PSR-11](https://www.php-fig.org/psr/psr-11/) compliant
service container that manages your application's shared objects and services.

The container replaces the former `Lucent\Service` registry. It is scoped to
the application singleton and is accessible through the `App` facade:

```php
use Lucent\Facades\App;

$container = App::container();
```

## PSR-11 Compliance

The container implements `Psr\Container\ContainerInterface`, providing two
methods:

| Method     | Description                                              |
|------------|----------------------------------------------------------|
| `get($id)` | Resolves and returns the entry for the given identifier  |
| `has($id)` | Returns whether an entry exists for the given identifier |

`get()` throws `Lucent\Container\NotFoundException` (implementing
`Psr\Container\NotFoundExceptionInterface`) when no entry exists, and
`Lucent\Container\ContainerException` (implementing
`Psr\Container\ContainerExceptionInterface`) when an entry exists but cannot
be resolved.

## Registering Services

### Register an Existing Instance

`instance()` stores an object under an identifier. Pass the object alone to
key it by its class name, or give an explicit identifier:

```php
$container->instance($logger);                       // keyed by Logger::class
$container->instance(LoggerInterface::class, $logger);
$container->instance(Client::class, $httpClient);
```

### Shared Singleton from a Class Name

`singleton()` with a class-string instantiates the class lazily and caches it
as a shared singleton:

```php
$container->singleton(Mailer::class);
$mailer = $container->get(Mailer::class); // same instance every time
```

You can register an implementation under a different (abstract) identifier:

```php
$container->singleton(MailerInterface::class, SmtpMailer::class);
```

The concrete can be a class-string, a factory closure, or omitted entirely
(defaults to the abstract). The abstract itself can also be a factory closure
whose return type names the identifier:

```php
$container->singleton(Mailer::class);                              // class-string, defaults to abstract
$container->singleton(MailerInterface::class, SmtpMailer::class);  // class-string under an interface
$container->singleton(Mailer::class, fn () => new Mailer(...));    // factory closure
$container->singleton(fn (): Mailer => new Mailer(...));           // closure abstract, keyed by Mailer::class
```

### Lazy Singleton from a Closure

`singleton()` also accepts a factory callable. The factory is not invoked
until the first `get()`, and its result is cached and shared thereafter. The
abstract identifier is always the leading argument:

```php
$container->singleton(
    Mailer::class,
    static fn () => new Mailer(config('mail.host'), config('mail.port')),
);

// Factory only runs here, and only once:
$mailer = $container->get(Mailer::class);
```

### Non-Shared Bindings

`bind()` registers a factory that is invoked on **every** `get()` call, so
each resolution returns a fresh instance. Use it when a service must not be
shared (e.g. a per-request connection):

```php
$container->bind(
    Connection::class,
    static fn () => new Connection($dsn),
);

$a = $container->get(Connection::class);
$b = $container->get(Connection::class); // $a !== $b
```

You can also pass a class-string as the concrete, which is instantiated fresh
on each resolution (and defaults to the abstract as its identifier). As with
`singleton()`, the abstract can be a factory closure whose return type names
the identifier:

```php
$container->bind(Connection::class);                              // class-string, defaults to abstract
$container->bind(ConnectionInterface::class, PDOConnection::class); // class-string under an interface
$container->bind(Connection::class, fn () => new Connection($dsn)); // factory closure
$container->bind(fn (): Connection => new Connection($dsn));       // closure abstract, keyed by Connection::class
```

### Aliases

`alias()` points a second identifier at an already-registered abstract so
both resolve to the **same** instance, without re-instantiating it:

```php
$container->singleton(MailerInterface::class, SmtpMailer::class);
$container->alias(MailerInterface::class, SmtpMailer::class);

$a = $container->get(MailerInterface::class);
$b = $container->get(SmtpMailer::class); // $a === $b
```

Aliases are resolved lazily at `get()` time, so the abstract does not need to
be registered yet when the alias is created.

### Removing an Entry

`remove()` resolves the identifier first (like `get()`/`has()`), then clears
the entry **and** every alias that (transitively) resolves to it, so no
dangling aliases remain:

```php
$container->singleton(MailerInterface::class, SmtpMailer::class);
$container->alias(MailerInterface::class, SmtpMailer::class);

$container->remove(MailerInterface::class);
$container->has(SmtpMailer::class); // false — alias removed too
```

Passing an alias removes the underlying entry and all its aliases:

```php
$container->remove(SmtpMailer::class); // same as remove(MailerInterface::class)
```

To remove a single alias without touching the entry it points to, use
`removeAlias()`:

```php
$container->removeAlias(SmtpMailer::class);
$container->has(SmtpMailer::class);      // false
$container->has(MailerInterface::class); // true — entry intact
```

Re-registering an entry (via `singleton()`, `bind()`, or `instance()`) does
**not** remove aliases pointing to it, so the "alias before abstract" pattern
survives re-registration.

## Resolution

`get()` returns the same shared instance for any singleton or instance you
registered:

```php
if ($container->has(Mailer::class)) {
    $mailer = $container->get(Mailer::class);
}
```

Accessing an unregistered identifier throws a not-found exception:

```php
try {
    $container->get(MissingService::class);
} catch (Psr\Container\NotFoundExceptionInterface $e) {
    // handle gracefully
}
```

## Controller Injection

Services registered under their interface or class name are automatically
injected into **controller constructors** and **controller method parameters**
that type-hint the identifier:

```php
namespace App\Controllers;

use App\Services\MailerInterface;

class WelcomeController
{
    public function __construct(private MailerInterface $mailer)
    {
    }

    public function index(): Response
    {
        return $this->mailer->send('Welcome!');
    }
}
```

Register the implementation once, and Lucent resolves it on every request:

```php
use Lucent\Facades\App;

App::container()->singleton(MailerInterface::class, SmtpMailer::class);
```

## Sharing with the HTTP Facade

Some Lucent facades register their collaborators on the container so they are
shared. For example, `Http::client()` registers the shared `Client` under
`Client::class`:

```php
$sameClient = App::container()->get(Client::class);
```
