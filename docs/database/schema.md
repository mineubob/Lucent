[Home](../../README.md)

# Schema in Lucent

## Introduction

The `Schema` class is Lucent's interface for defining and managing your database structure. It provides a fluent, driver-agnostic API for creating tables, defining columns, and querying the existing database structure — without writing raw SQL.

Schema works across both MySQL and SQLite automatically, translating your column definitions into the correct SQL syntax for whichever driver is configured.

---

## Creating Tables

Use `Schema::table()` to define a table. Pass the table name and a callback that receives a `Table` instance, then call `create()` to execute the statement.

```php
use Lucent\Database\Schema;

Schema::table('users', function ($table) {
    $table->int('id')->autoIncrement()->primaryKey();
    $table->varchar('name')->length(100);
    $table->varchar('email')->length(255)->unique();
    $table->boolean('active')->default(1);
    $table->timestamp('created_at')->nullable();
})->create();
```

By default, `create()` uses `CREATE TABLE IF NOT EXISTS`, so it is safe to call on every boot without checking first. To force creation without the `IF NOT EXISTS` guard, pass `false`:

```php
Schema::table('users', function ($table) {
    // ...
})->create(false);
```

---

## Column Types

The following column types are available on the `Table` instance. All methods return the column object so you can chain modifiers.

### String Types

```php
$table->varchar('name')->length(100);        // Variable-length string, length required
$table->char('code')->length(10);            // Fixed-length string, length required
$table->text('bio');                         // Unbounded text
$table->mediumtext('body');                  // Medium text blob
$table->longtext('content');                 // Large text blob
```

### Numeric Types

```php
$table->int('id');                           // Standard integer
$table->bigint('external_id');               // Large integer
$table->tinyint('status');                   // Small integer (0–255)
$table->float('rating');                     // Single-precision float
$table->double('price');                     // Double-precision float
$table->decimal('amount');                   // Fixed-point decimal (20,2)
$table->boolean('active');                   // Stored as tinyint, pre/post processed as bool
```

### Date & Time Types

```php
$table->date('birthday');                    // Date only (YYYY-MM-DD)
$table->timestamp('created_at');             // Date and time
```

### Other Types

```php
$table->json('metadata');                    // JSON blob
$table->binary('hash')->length(64);          // Binary data, length required
$table->enum('status')->values(['draft', 'published', 'archived']);
```

---

## Column Modifiers

Modifiers can be chained onto any column after the type method. Numeric types (`int`, `bigint`, `tinyint`, `float`, `double`, `decimal`, `boolean`) also support `autoIncrement()` and `unsigned()`.

```php
// Available on all column types
->nullable()                  // Allow NULL values
->default($value)             // Set a default value
->primaryKey()                // Mark as primary key (implies NOT NULL)
->unique()                    // Add a UNIQUE constraint
->length(int $length)         // Set character/binary length
->values(array $values)       // Set allowed values for ENUM columns
->references(Reference $ref)  // Add a foreign key reference

// Numeric columns only
->autoIncrement()             // AUTO_INCREMENT (MySQL) / AUTOINCREMENT (SQLite)
->unsigned()                  // UNSIGNED (MySQL only, ignored on SQLite)
```

### Examples

```php
// Primary key with auto increment
$table->int('id')->autoIncrement()->primaryKey();

// Nullable column with a default
$table->varchar('nickname')->length(50)->nullable()->default('Anonymous');

// Unique email
$table->varchar('email')->length(255)->unique();

// Unsigned big integer
$table->bigint('views')->unsigned()->default(0);

// Enum with allowed values
$table->enum('role')->values(['admin', 'editor', 'viewer'])->default('viewer');
```

---

## Foreign Keys

Use `references()` with a `Reference` instance to define a foreign key constraint inline on the column.

```php
use Lucent\Database\Schema;
use Lucent\Database\Schema\Reference;

Schema::table('posts', function ($table) {
    $table->int('id')->autoIncrement()->primaryKey();
    $table->int('user_id')->references(new Reference('users', 'id'));
    $table->varchar('title')->length(200);
    $table->text('body');
})->create();
```

You can also build a `Reference` from a string in `table(column)` format:

```php
use Lucent\Database\Schema\Reference;

$ref = Reference::fromString('users(id)');
```

Or directly from a Model class, which resolves the table name and primary key automatically:

```php
use Lucent\Database\Schema\Reference;
use App\Models\User;

$ref = Reference::fromString(User::class);
```

---

## Checking Table Existence

Before creating or modifying tables, you can check whether they already exist:

```php
$table = Schema::table('users');

if ($table->exists()) {
    // Table already exists
}
```

---

## Dropping Tables

```php
Schema::table('users')->drop();
```

When dropping tables with foreign key constraints, disable FK checks first to avoid constraint violations regardless of drop order:

```php
use Lucent\Database;
use Lucent\Database\Schema;

Database::disabling('foreign_key_checks', function () {
    Schema::table('order_items')->drop();
    Schema::table('orders')->drop();
    Schema::table('customers')->drop();
});
```

---

## Listing All Tables

`Schema::list()` returns an array of `Table` instances for every table in the current database:

```php
$tables = Schema::list();

foreach ($tables as $table) {
    echo $table->name . PHP_EOL;
}
```

This is how Lucent's own test setup drops all tables before each test run:

```php
Database::disabling('foreign_key_checks', function () {
    foreach (Schema::list() as $table) {
        $table->drop();
    }
});
```

---

## Checking Column Existence

You can check whether a specific column exists on a table:

```php
$table = Schema::table('users');
$column = $table->varchar('email')->length(255);

if ($column->exists()) {
    // Column already exists on the table
}
```

---

## Real-World Example: Application Schema Bootstrap

The following example shows a typical schema setup for a multi-tenant CRM, creating several related tables in the correct order with foreign key constraints.

```php
<?php

use Lucent\Database;
use Lucent\Database\Schema;
use Lucent\Database\Schema\Reference;

// Drop everything cleanly before rebuilding
Database::disabling('foreign_key_checks', function () {
    foreach (Schema::list() as $table) {
        $table->drop();
    }
});

// Tenants
Schema::table('tenants', function ($table) {
    $table->int('id')->autoIncrement()->primaryKey();
    $table->varchar('subdomain')->length(100)->unique();
    $table->varchar('db_host')->length(255);
    $table->varchar('db_name')->length(100);
    $table->varchar('db_user')->length(100);
    $table->varchar('db_password')->length(255);
    $table->timestamp('created_at')->nullable();
})->create();

// Contacts
Schema::table('contacts', function ($table) {
    $table->int('id')->autoIncrement()->primaryKey();
    $table->varchar('first_name')->length(100);
    $table->varchar('last_name')->length(100);
    $table->varchar('email')->length(255)->unique();
    $table->varchar('phone')->length(20)->nullable();
    $table->varchar('address_line_1')->length(255)->nullable();
    $table->varchar('address_line_2')->length(255)->nullable();
    $table->varchar('city')->length(100)->nullable();
    $table->varchar('state_province_region')->length(100)->nullable();
    $table->varchar('postal_code')->length(20)->nullable();
    $table->varchar('country')->length(2)->nullable();  // ISO 3166-1 alpha-2
    $table->timestamp('created_at')->nullable();
})->create();

// Leads
Schema::table('leads', function ($table) {
    $table->int('id')->autoIncrement()->primaryKey();
    $table->varchar('name')->length(255);
    $table->enum('type')->values(['NEW_BUSINESS', 'EXISTING_BUSINESS'])->nullable();
    $table->enum('label')->values(['HOT', 'WARM', 'COLD'])->nullable();
    $table->enum('stage')->values(['NEW', 'ATTEMPTING', 'CONNECTED', 'QUALIFIED', 'DISQUALIFIED'])->default('NEW');
    $table->int('contact_id')->references(new Reference('contacts', 'id'));
    $table->int('owner_id')->nullable();
    $table->timestamp('created_at')->nullable();
})->create();
```

---

## API Reference

### `Schema`

| Method | Description |
|---|---|
| `Schema::table(name, callback?)` | Define a table and return a `Table` instance |
| `Schema::list()` | Return all tables in the current database as `Table[]` |

### `Table`

| Method | Returns | Description |
|---|---|---|
| `->create(ifNotExists?)` | `bool` | Execute `CREATE TABLE` |
| `->drop()` | `bool` | Execute `DROP TABLE IF EXISTS` |
| `->exists()` | `bool` | Check if the table exists |
| `->toSql(ifNotExists?)` | `string` | Get the raw SQL without executing |

### Column Type Methods on `Table`

| Method | SQL Type | Notes |
|---|---|---|
| `->int(name)` | `INT` | Returns `NumericColumn` |
| `->bigint(name)` | `BIGINT` | Returns `NumericColumn` |
| `->tinyint(name)` | `TINYINT` | Returns `NumericColumn` |
| `->boolean(name)` | `TINYINT` | Returns `NumericColumn`, bool pre/post processing |
| `->float(name)` | `FLOAT` | Returns `NumericColumn` |
| `->double(name)` | `DOUBLE` | Returns `NumericColumn` |
| `->decimal(name)` | `DECIMAL(20,2)` | Returns `NumericColumn` |
| `->varchar(name)` | `VARCHAR` | Requires `->length()` |
| `->char(name)` | `CHAR` | Requires `->length()` |
| `->text(name)` | `TEXT` | |
| `->mediumtext(name)` | `MEDIUMTEXT` | |
| `->longtext(name)` | `LONGTEXT` | |
| `->json(name)` | `JSON` | |
| `->date(name)` | `DATE` | |
| `->timestamp(name)` | `TIMESTAMP` | |
| `->binary(name)` | `BINARY` | Requires `->length()` |
| `->enum(name)` | `ENUM` | Requires `->values([...])` |

### Column Modifiers

| Modifier | Applies To | Description |
|---|---|---|
| `->nullable()` | All | Allow NULL values |
| `->default(value)` | All | Set a default value |
| `->primaryKey()` | All | Mark as primary key |
| `->unique()` | All | Add UNIQUE constraint |
| `->length(int)` | String / Binary | Set column length |
| `->values(array)` | ENUM | Set allowed values |
| `->references(Reference)` | All | Add foreign key reference |
| `->autoIncrement()` | Numeric only | Auto-increment on insert |
| `->unsigned()` | Numeric only | Unsigned (MySQL only) |