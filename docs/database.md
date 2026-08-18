[Home](../README.md)

# Database in Lucent

## Introduction

The `Database` class is Lucent's central facade for all database interactions. It manages connections, routes queries to the correct driver, and provides a clean static API for executing SQL against MySQL and SQLite databases.

By default, Lucent operates with a single database connection booted automatically from your environment variables — no setup required beyond your `.env` file. For more advanced use cases such as multi-tenancy, Lucent also supports a named connection pool that lets you register, switch between, and scope queries to multiple databases within a single request.

## Table Management

Looking to create or modify tables? That's handled by the `Schema` class. See the [Schema documentation](database/schema.md) for a full guide on defining tables, columns, constraints, and foreign keys.

## Basic Usage

For most applications, you never need to think about connection management. The `Database` class reads your environment variables and establishes a connection on first use.

### Environment Configuration

```ini
# MySQL
DB_DRIVER=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=my_database
DB_USERNAME=root
DB_PASSWORD=secret

# SQLite
DB_DRIVER=sqlite
DB_DATABASE=/storage/database.sqlite
```

For SQLite you can also use an **in-memory** database by setting `DB_DATABASE=:memory:`. This creates a database that lives entirely in memory and is destroyed when the connection closes. It is ideal for tests and other ephemeral use cases: it is faster (no file I/O), leaves no files behind, and each connection gets its own fully isolated database.

```ini
# SQLite (in-memory)
DB_DRIVER=sqlite
DB_DATABASE=:memory:
```

### Running Queries

```php
use Lucent\Database;

// Select all rows
$users = Database::select("SELECT * FROM users");

// Select a single row
$user = Database::select("SELECT * FROM users WHERE id = ?", false, [$id]);

// Insert
Database::insert("INSERT INTO users (name, email) VALUES (?, ?)", [$name, $email]);

// Update
Database::update("UPDATE users SET name = ? WHERE id = ?", [$name, $id]);

// Delete
Database::delete("DELETE FROM users WHERE id = ?", [$id]);

// Raw statement (DDL, SET, PRAGMA, etc.)
Database::statement("ALTER TABLE users ADD COLUMN verified TINYINT DEFAULT 0");
```

All query methods return `false` on failure rather than throwing — errors are caught internally and logged to the `lucent.db` log channel.

### Transactions

Wrap multiple operations in a transaction using a callback. The transaction is automatically committed if the callback returns a truthy value, or rolled back if it returns `false` or throws an exception.

```php
Database::transaction(function () use ($orderId, $items) {
    Database::insert("INSERT INTO orders (id) VALUES (?)", [$orderId]);

    foreach ($items as $item) {
        Database::insert(
            "INSERT INTO order_items (order_id, product_id, qty) VALUES (?, ?, ?)",
            [$orderId, $item['product_id'], $item['qty']]
        );
    }

    return true;
});
```

### Disabling Features

Some operations require temporarily disabling database constraints. Use `disabling()` to wrap those operations safely — the feature is always re-enabled afterwards, even if an exception is thrown.

```php
Database::disabling('foreign_key_checks', function () {
    Schema::dropTable('order_items');
    Schema::dropTable('orders');
});
```

Supported features vary by driver:

| Feature | MySQL | SQLite |
|---|---|---|
| `foreign_key_checks` | ✅ | ✅ |

---

## Multiple Database Connections

Lucent supports a named connection pool for applications that need to query more than one database — the most common case being multi-tenant SaaS applications where each tenant has their own database.

### How It Works

1. The `'default'` connection is always booted from environment variables and is always available.
2. Additional named connections are registered at runtime using `addConnection()`.
3. You switch between connections using `switchTo()` or the safer `usingConnection()`.
4. All standard query methods (`select`, `insert`, etc.) always operate against the currently active connection.

### Registering a Connection

Use `addConnection()` to register a named connection from a configuration array. This does not switch the active connection.

```php
Database::addConnection('tenant', [
    'driver'   => 'mysql',
    'host'     => 'tenant.db.internal',
    'database' => 'tenant_acme',
    'username' => 'acme_user',
    'password' => 'secret',
    'port'     => '3306',        // optional, defaults to 3306
]);
```

For SQLite:

```php
Database::addConnection('archive', [
    'driver'   => 'sqlite',
    'database' => '/storage/archive.sqlite',
]);
```

### Checking if a Connection Exists

```php
if (Database::hasConnection('tenant')) {
    // Safe to switch or query
}
```

### Getting the Active Connection Name

```php
$name = Database::getActiveConnectionName(); // 'default'
```

---

## Switching Connections

There are two ways to switch connections, each suited to different situations.

### `switchTo()` — Global Switch

`switchTo()` changes the active connection for all subsequent queries in the current request. You are responsible for switching back when done.

```php
Database::switchTo('tenant');

// All queries now target the tenant DB
$leads = Database::select("SELECT * FROM leads");
$contacts = Database::select("SELECT * FROM contacts");

// Switch back manually
Database::switchTo('default');
```

> **Warning:** If you forget to switch back, all subsequent queries in the same request will continue to target the wrong database. Prefer `usingConnection()` to avoid this.

### `usingConnection()` — Scoped Switch (Recommended)

`usingConnection()` switches to a named connection for the duration of a callback, then automatically restores the previous connection — even if the callback throws an exception.

```php
$leads = Database::usingConnection('tenant', function () {
    return Database::select("SELECT * FROM leads");
});

// Active connection is automatically back to 'default' here
```

This is the recommended approach for tenant queries inside middleware or service classes, as it eliminates the risk of connection bleed.

---

## Real-World Example: Multi-Tenant Middleware

The following example shows how connection switching integrates cleanly into a request lifecycle using middleware.

### 1. Tenant Model (on the central database)

```php
<?php

namespace App\Models;

use Lucent\Model;
use Lucent\Model\Column;
use Lucent\Model\ColumnType;

class Tenant extends Model
{
    #[Column(type: ColumnType::INT, primaryKey: true, autoIncrement: true)]
    public int $id;

    #[Column(type: ColumnType::VARCHAR, length: 100)]
    public string $subdomain;

    #[Column(type: ColumnType::VARCHAR, length: 255)]
    public string $db_host;

    #[Column(type: ColumnType::VARCHAR, length: 100)]
    public string $db_name;

    #[Column(type: ColumnType::VARCHAR, length: 100)]
    public string $db_user;

    #[Column(type: ColumnType::VARCHAR, length: 255)]
    public string $db_password;

    public function dbConfig(): array
    {
        return [
            'driver'   => 'mysql',
            'host'     => $this->db_host,
            'database' => $this->db_name,
            'username' => $this->db_user,
            'password' => $this->db_password,
        ];
    }
}
```

### 2. Tenant Middleware

```php
<?php

namespace App\Middleware;

use App\Models\Tenant;
use Lucent\Database;
use Lucent\Http\Message\Response;
use Lucent\Http\Message\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class TenantMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Resolve the tenant from the subdomain (queries the central/default DB)
        $subdomain = $request->getHeaderLine('X-Tenant');
        $tenant = Tenant::where('subdomain', $subdomain)->getFirst();

        if (!$tenant) {
            // Short-circuit with a 404 if the tenant doesn't exist
            return (new Response())->withStatus(404);
        }

        // Register the tenant's database connection
        Database::addConnection('tenant', $tenant->dbConfig());

        // Store the tenant on the request for use in controllers
        $request = $request->withAttribute('tenant', $tenant);

        return $handler->handle($request);
    }
}
```

### 3. Lead Controller

```php
<?php

namespace App\Controllers;

use App\Models\Lead;
use Lucent\Database;
use Lucent\Http\Message\Response;
use Lucent\Http\Message\ServerRequest;

class LeadController
{
    public function index(ServerRequest $request): Response
    {
        // All queries inside this block run against the tenant DB
        $leads = Database::usingConnection('tenant', fn() => Lead::get());

        return Response::json(['leads' => $leads], 200);
    }

    public function show(ServerRequest $request, Lead $lead): Response
    {
        return Database::usingConnection('tenant', function () use ($lead) {
            return Response::json(['lead' => $lead], 200);
        });
    }
}
```

### 4. Route Definitions

```php
<?php

use App\Controllers\LeadController;
use App\Middleware\TenantMiddleware;
use Lucent\Facades\Route;

Route::rest()->group('leads')
    ->prefix('/leads')
    ->defaultController(LeadController::class)
    ->middleware([TenantMiddleware::class])
    ->get(path: '/', method: 'index')
    ->get(path: '/{lead}', method: 'show');
```

### How It All Works Together

1. **Request arrives** at `/leads` with an `X-Tenant: acme` header.
2. **TenantMiddleware runs** — queries the central (default) DB to find the `acme` tenant record, then registers its database config as the `'tenant'` connection.
3. **Controller executes** — `usingConnection('tenant', ...)` scopes all model queries to the tenant's database and automatically restores `'default'` when done.
4. **Next request** starts clean — `'default'` is always the active connection at the start of every request.

---

## Connection Lifecycle

### Removing a Connection

If you need to explicitly close and deregister a connection during a request, use `removeConnection()`. If the removed connection was the active one, the active connection automatically resets to `'default'`.

```php
Database::removeConnection('tenant');
```

Calling `removeConnection()` on a name that doesn't exist is safe — it does nothing.

### Resetting All Connections

`reset()` closes every connection in the pool and returns the database layer to its initial state. The next query will re-boot the default connection from environment variables. This is primarily useful in testing.

```php
Database::reset();
```

### Configuring the Database in Tests

In tests you usually don't want to write a `.env` file just to switch database drivers. Configure the database in memory instead:

```php
use Lucent\Application;

// Replace the whole environment (e.g. switch to a fresh driver per dataset).
Application::getInstance()->setEnv([
    'DB_DRIVER'   => 'sqlite',
    'DB_DATABASE' => '/storage/database.sqlite',
], false);

// Or merge individual keys on top of the existing environment.
Application::getInstance()->setEnv(['DEBUG' => true]);

// Re-boot the connection from the new environment.
Database::reset();
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
| `Database::select(query, fetchAll, args)` | Execute a SELECT and return rows |
| `Database::insert(query, args)` | Execute an INSERT |
| `Database::update(query, args)` | Execute an UPDATE |
| `Database::delete(query, args)` | Execute a DELETE |
| `Database::statement(query, args)` | Execute a raw SQL statement |
| `Database::transaction(callback)` | Run a callback inside a transaction |
| `Database::disabling(feature, callback)` | Disable a DB feature for a callback |
| `Database::addConnection(name, config)` | Register a named connection |
| `Database::switchTo(name)` | Globally switch the active connection |
| `Database::usingConnection(name, callback)` | Scope queries to a connection, then restore |
| `Database::connection(name)` | Get a named connection instance directly |
| `Database::hasConnection(name)` | Check if a named connection is registered |
| `Database::getActiveConnectionName()` | Get the current active connection name |
| `Database::removeConnection(name)` | Close and deregister a named connection |
| `Database::getDriver()` | Get the active driver instance |
| `Database::reset()` | Close all connections and reset to initial state |

---

## Best Practices

1. **Prefer `usingConnection()` over `switchTo()`** — it restores the previous connection automatically and is safe from bleed even when exceptions occur.
2. **Register connections in middleware** — resolve tenant credentials before the controller runs so connection setup is centralised and consistent.
3. **Always query the central DB first** — resolve which tenant you're serving before switching connections. The default connection is always available for this.
4. **Don't hardcode connection names in models** — keep connection switching in middleware or service classes so models remain portable.
5. **Use `hasConnection()` before `switchTo()`** — if connection registration is conditional, guard with `hasConnection()` to avoid unexpected exceptions.