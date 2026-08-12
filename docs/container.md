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

`instance()` stores an object under an identifier. The identifier defaults to
the object's class name, or you can supply an alias:

```php
$container->instance($logger, LoggerInterface::class);
$container->instance($httpClient);           // keyed by Client::class
```

### Shared Singleton from a Class Name

`singleton()` with a class-string instantiates the class eagerly and caches it
as a shared singleton:

```php
$container->singleton(Mailer::class);
$mailer = $container->get(Mailer::class); // same instance every time
```

You can register it under a different identifier with an alias:

```php
$container->singleton(SomeImplementation::class, SomeInterface::class);
```

### Lazy Singleton from a Closure

`singleton()` also accepts a factory callable. The factory is not invoked
until the first `get()`, and its result is cached and shared thereafter.
Because a closure has no intrinsic name, you must register it under an
explicit alias:

```php
$container->singleton(
    static fn () => new Mailer(config('mail.host'), config('mail.port')),
    Mailer::class,
);

// Factory only runs here, and only once:
$mailer = $container->get(Mailer::class);
```

### Non-Shared Bindings

`bind()` registers a factory that is invoked on **every** `get()` call, so
each resolution returns a fresh instance. Use it when a service must not be
shared (e.g. a per-request connection). As with `singleton()`, a closure must
be registered under an explicit alias:

```php
$container->bind(
    static fn () => new Connection($dsn),
    Connection::class,
);

$a = $container->get(Connection::class);
$b = $container->get(Connection::class); // $a !== $b
```

You can also pass a class-string to `bind()`, which is instantiated fresh on
each resolution (and defaults to the class name as its identifier).

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

App::container()->singleton(SmtpMailer::class, MailerInterface::class);
```

## Sharing with the HTTP Facade

Some Lucent facades register their collaborators on the container so they are
shared. For example, `Http::client()` registers the shared `Client` under
`Client::class`:

```php
$sameClient = App::container()->get(Client::class);
```
