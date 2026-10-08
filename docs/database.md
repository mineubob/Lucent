[Home](../README.md)

# Database in Lucent

## Introduction

Lucent's database layer is [**Radiant**](https://github.com/blueprintau/radiant) (`blueprintau/radiant`) — a typed, attribute-driven ORM and schema toolkit. Lucent wires Radiant's `DatabaseManager` from your `.env` at boot, so the `BlueprintAU\Radiant\Database` facade is ready to use with no setup beyond your environment file.

By default, Lucent operates with a single database connection built from your environment variables. For advanced use cases such as multi-tenancy, Radiant supports a named connection pool that lets you register, switch between, and scope queries to multiple databases within a single request.

## Table Management

Looking to create or modify tables? That's handled by the `sync` command — a diff-based schema synchronizer driven by your models' attributes. See the [Schema documentation](database/schema.md) for the full guide, and [Command Line](commandline.md) for the `sync` command reference.

## Basic Usage

For most applications, you never need to think about connection management. Lucent reads your environment variables and builds the `DatabaseManager` at boot.

### Environment Configuration

```ini
# MySQL
DB_DRIVER=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=my_database
DB_USERNAME=root
DB_PASSWORD=secret

# SQLite — relative to the project root
DB_DRIVER=sqlite
DB_DATABASE=storage/database.sqlite

# SQLite — absolute path (used verbatim)
DB_DRIVER=sqlite
DB_DATABASE=/var/data/database.sqlite
```

For SQLite, `DB_DATABASE` is a **filesystem path**. A relative path is resolved against your project's root directory (where `.env` lives), and absolute paths are used verbatim — so the SQLite file can live anywhere on disk. The database file is created automatically by SQLite when the connection opens.

For SQLite you can also use an **in-memory** database by setting `DB_DATABASE=:memory:`. This creates a database that lives entirely in memory and is destroyed when the connection closes. It is ideal for tests and other ephemeral use cases: it is faster (no file I/O), leaves no files behind, and each connection gets its own fully isolated database.

```ini
# SQLite (in-memory)
DB_DRIVER=sqlite
DB_DATABASE=:memory:
```

Supported drivers: `mysql`, `sqlite`, `pgsql`, `csv`.

### Running Queries

Radiant's `Database` facade provides raw SQL access and fluent query building:

```php
use BlueprintAU\Radiant\Database;

// Fluent query builder
$users = Database::table('users')->where('active', '=', 1)->get();

// Raw select — every matching row as an object
$users = Database::select("SELECT * FROM users");

// Raw statement that returns no result set
Database::statement("ALTER TABLE users ADD COLUMN verified TINYINT DEFAULT 0");

// Raw statement returning affected row count
$count = Database::affectingStatement("UPDATE users SET active = 1 WHERE id = ?", [$id]);
```

Errors throw a `QueryException` rather than returning `false` — failures are loud, not silent.

### Transactions

Wrap multiple operations in a transaction using a callback. The transaction is automatically committed if the callback succeeds, or rolled back if it throws.

```php
use BlueprintAU\Radiant\Database\Connections\SqlConnection;

$connection = Database::sqlConnection();

$connection->transaction(function (SqlConnection $db) use ($orderId, $items) {
    $db->table('orders')->insert(['id' => $orderId]);

    foreach ($items as $item) {
        $db->table('order_items')->insert([
            'order_id'   => $orderId,
            'product_id' => $item['product_id'],
            'qty'        => $item['qty'],
        ]);
    }
});
```

---

## Multiple Database Connections

Radiant supports a named connection pool for applications that need to query more than one database — the most common case being multi-tenant SaaS applications where each tenant has their own database.

### How It Works

1. The `'default'` connection is always built from environment variables and is always available.
2. Additional named connections are registered at runtime using `addConnection()`.
3. You switch between connections using `usingConnection()` — the safe, scoped switch.
4. All queries operate against the currently active connection.

### Registering a Connection

Use `addConnection()` to register a named connection from a configuration array. This does not switch the active connection.

```php
Database::manager()->addConnection('tenant', [
    'driver'   => 'mysql',
    'host'     => 'tenant.db.internal',
    'port'     => 3306,
    'database' => 'tenant_acme',
    'username' => 'acme_user',
    'password' => 'secret',
]);
```

For SQLite:

```php
Database::manager()->addConnection('archive', [
    'driver'   => 'sqlite',
    'database' => 'storage/archive.sqlite',
]);
```

### Checking if a Connection Exists

```php
if (Database::manager()->hasConnection('tenant')) {
    // Safe to switch or query
}
```

---

## Switching Connections

### `usingConnection()` — Scoped Switch (Recommended)

`usingConnection()` switches to a named connection for the duration of a callback, then automatically restores the previous connection — even if the callback throws an exception.

```php
$leads = Database::usingConnection('tenant', function () {
    return Database::table('leads')->get();
});

// Active connection is automatically back to 'default' here
```

This is the recommended approach for tenant queries inside middleware or service classes, as it eliminates the risk of connection bleed.

---

## Real-World Example: Multi-Tenant Middleware

The typical multi-tenant setup resolves the tenant in middleware, registers
its credentials as a named connection, and scopes controller queries with
`usingConnection()`:

```php
<?php

namespace App\Middleware;

use App\Models\Tenant;
use BlueprintAU\Radiant\Database;
use Lucent\Http\Message\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class TenantMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // 1. Resolve the tenant from the central (default) DB
        $tenant = Tenant::where('subdomain', '=', $request->getHeaderLine('X-Tenant'))->first();

        if (!$tenant) {
            return (new Response())->withStatus(404);
        }

        // 2. Register the tenant's credentials as a named connection
        Database::manager()->addConnection('tenant', $tenant->dbConfig());

        // 3. Stash the tenant for controllers
        return $handler->handle($request->withAttribute('tenant', $tenant));
    }
}
```

Controllers then scope queries — everything inside the callback hits the
tenant's database, and `'default'` is restored afterwards (even on error).
Route model binding (`#[Bind]`) composes with this too — see
[Route Model Binding](route-model-binding.md) for scoping bindings to the
active tenant:

```php
// All queries inside this block run against the tenant DB
$leads = Database::usingConnection('tenant', fn() => Lead::all());
```

The full flow: the request arrives → middleware resolves the tenant from the
central DB and registers its connection → the controller runs against the
tenant DB → the next request starts clean on `'default'`.

---

## Connection Lifecycle

### Evicting a Connection

If you need to explicitly close and evict a resolved connection during a request, use `flush()`. Any open transaction on the connection is rolled back first.

```php
Database::manager()->flush('tenant');
```

Calling `flush()` on a name that isn't resolved is safe — it does nothing.

### Evicting All Connections

`flush()` with no arguments evicts every resolved connection and returns the pool to its initial state. The next query rebuilds the connection from its config. This is primarily useful in testing.

```php
Database::manager()->flush();
```

### Configuring the Database in Tests

In tests you usually don't want to write a `.env` file just to switch database drivers. Configure the database in memory instead:

```php
use Lucent\Application;

// Replace the whole environment (e.g. switch to a fresh driver per dataset).
Application::getInstance()->setEnv([
    'DB_DRIVER'   => 'sqlite',
    'DB_DATABASE' => ':memory:',
], false);

// setEnv() re-builds the DatabaseManager from the new environment.
```

`setEnv()` normalises keys to upper-case, casts values to strings, and re-configures the database layer. By default it merges into the existing environment; pass `false` as the second argument to replace it entirely.

If you do need to load a specific `.env` file (rather than the default `FileSystem::rootPath()/.env`), pass its path to `loadEnv()`:

```php
Application::getInstance()->loadEnv('/path/to/.env');
```

`loadEnv()` replaces the in-memory environment with the file's contents — the file is the source of truth. Use `setEnv()` when you want to overlay keys instead.

---

## API Reference

| Method | Description |
|---|---|
| `Database::table(name)` | Start a fluent query against a table |
| `Database::select(query, args)` | Run raw SQL, return rows as objects |
| `Database::statement(query, args)` | Run a raw SQL statement (no result set) |
| `Database::affectingStatement(query, args)` | Run raw SQL, return affected row count |
| `Database::connection(name?)` | Get a named connection instance |
| `Database::sqlConnection(name?)` | Get a named connection, narrowed to SQL |
| `Database::usingConnection(name, callback)` | Scope queries to a connection, then restore |
| `Database::manager()` | The underlying DatabaseManager |
| `Database::manager()->addConnection(name, config)` | Register a named connection |
| `Database::manager()->hasConnection(name)` | Check if a named connection is registered |
| `Database::manager()->flush(name?)` | Evict one or all resolved connections |
| `Database::manager()->disconnect(name)` | Evict one connection (readable alias) |
| `Database::manager()->usingConnection(name, callback)` | Manager-level scoped switch |

---

## Best Practices

1. **Prefer `usingConnection()` over manual switching** — it restores the previous connection automatically and is safe from bleed even when exceptions occur.
2. **Register connections in middleware** — resolve tenant credentials before the controller runs so connection setup is centralised and consistent.
3. **Always query the central DB first** — resolve which tenant you're serving before switching connections. The default connection is always available for this.
4. **Don't hardcode connection names in models** — keep connection switching in middleware or service classes so models remain portable.
