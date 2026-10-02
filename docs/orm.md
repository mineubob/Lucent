[Home](../README.md)

# ORM (Radiant Models)

Lucent's ORM is [**Radiant**](https://github.com/blueprintau/radiant) — an Active Record ORM where typed properties + `#[Column]` attributes declare the schema. Radiant is developed in its own repository with its own documentation; this page covers the essentials for Lucent applications.

## Defining a Model

Extend `BlueprintAU\Radiant\Model` and declare columns with the `#[Column]` attribute:

```php
<?php

namespace App\Models;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\ColumnType;
use BlueprintAU\Radiant\Model;

class User extends Model
{
    #[Column(ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public private(set) ?int $id;

    #[Column(ColumnType::String, length: 255)]
    protected string $email;

    #[Column(ColumnType::String, length: 100)]
    protected string $full_name;

    #[Column(ColumnType::Text, nullable: true)]
    protected ?string $bio = null;

    public function __construct(string $email, string $full_name)
    {
        $this->email = $email;
        $this->full_name = $full_name;
    }
}
```

Key points:

- **Typed properties are required** — every `#[Column]` property must declare a single named type so the cast pipeline has a contract.
- **The table name** defaults to the snake-cased plural of the class (`User` → `users`). Override with `#[Table('custom_name')]`.
- **Column names** default to the property name. Override with `#[Column(name: 'custom_column')]`.
- **Property visibility is respected** — `private(set)` properties are read-only from outside; use methods to mutate.
- **Auto-increment PKs must be `ColumnType::BigInt`** — SQLite renders `Int` as `int`, and `AUTOINCREMENT` is only legal on `INTEGER PRIMARY KEY`.

## Column Types

The `ColumnType` enum drives DDL and casting:

| Type | Property type | Notes |
|---|---|---|
| `ColumnType::BigInt` | `int` | 64-bit integer — the default for auto-increment PKs |
| `ColumnType::Int` | `int` | 32-bit integer |
| `ColumnType::String` | `string` | Requires `length:` |
| `ColumnType::Char` | `string` | Fixed-length, requires `length:` |
| `ColumnType::Text` | `string` | Unbounded text |
| `ColumnType::Decimal` | `float` | Requires `precision:` + `scale:` |
| `ColumnType::Float` | `float` | Floating point |
| `ColumnType::Boolean` | `bool` | |
| `ColumnType::Date` | `string` / `Carbon` | Calendar date |
| `ColumnType::DateTime` | `string` / `Carbon` | Date-time |
| `ColumnType::Timestamp` | `int` / `Carbon` | Unix timestamp |
| `ColumnType::Json` | `array` | JSON document |
| `ColumnType::Enum` | `string` | Requires `values:` (list or enum class-string) |
| `ColumnType::Binary` | `string` | Raw bytes |
| `ColumnType::Uuid` | `string` | Fixed 36-character RFC 4122 UUID |

## Querying

Every model exposes a fluent query builder via `newQuery()` and static forwarders:

```php
use App\Models\User;

// Find by primary key
$user = User::find(1);

// Find or throw
$user = User::findOrFail(1);

// Where clauses — the operator is explicit
$user = User::where('email', '=', 'john@doe.com')->first();

// Get all matching
$users = User::where('active', '=', 1)->get();

// Ordering, limiting
$recent = User::orderBy('created_at', 'desc')->limit(10)->get();

// Aggregates
$count = User::where('active', '=', 1)->count();
$max   = User::max('age');

// Eager loading
$posts = Post::with('author', 'comments')->get();
```

Column names are **validated against the declared set** — an unknown column throws `InvalidArgumentException` rather than being interpolated into SQL.

## Creating and Updating

```php
$user = new User('john@doe.com', 'John Doe');
$user->save(); // INSERT — the auto-increment PK is populated

$user->full_name = 'Jack Harris'; // via a setter method if private(set)
$user->save(); // UPDATE of the dirty columns only

$user->delete(); // DELETE (or soft delete when SoftDeletes is used)
```

## Soft Deletes

Use the `SoftDeletes` trait — a nullable `deleted_at` column is added and every query auto-applies a `deleted_at IS NULL` scope:

```php
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\SoftDeletes;

class Post extends Model
{
    use SoftDeletes;

    // ...
}

$post->delete();      // soft delete — stamps deleted_at
$post->restore();     // clears deleted_at
$post->forceDelete(); // hard delete

Post::withTrashed()->get();  // include soft-deleted rows
Post::onlyTrashed()->get();  // only soft-deleted rows
```

## Timestamps

Use the `Timestamps` trait to add `created_at` / `updated_at` maintenance:

```php
use BlueprintAU\Radiant\Timestamps;

class Post extends Model
{
    use Timestamps;
    // ...
}
```

## Relationships

Radiant supports `BelongsTo`, `HasOne`, `HasMany`, `BelongsToMany`, and polymorphic relations. A relation is a method returning a `Relation` object:

```php
class Post extends Model
{
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

$post->author;          // lazy load
Post::with('author');   // eager load (one extra query, no N+1)
```

## Multi-Table Inheritance (MTI)

Extend a table-owning model to split columns across tables — the child table holds its own columns plus a derived key, and reads join the ancestor chain transparently:

```php
class Admin extends User
{
    #[Column(default: false)]
    public bool $can_reset_passwords;
}
```

The `admins` table holds `can_reset_passwords` + a foreign key to `users`; querying `Admin` joins both tables and hydrates a single virtual row.

## Route Model Binding

See [Route Model Binding](route-model-binding.md) for the `#[Bind]` attribute — opt-in resolution of model parameters from route variables.

## Schema Synchronization

The `sync` command diffs your models' declared schema against the live database and applies the changes. See [Schema](database/schema.md) and [Command Line](commandline.md).
