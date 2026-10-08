[Home](../README.md)

# Migrating to Radiant

This guide is for applications built on Lucent's original ORM (`Lucent\Model\Model`, `Lucent\Database`) upgrading to the Radiant-based database layer.

## Overview

What changed:

- **`Lucent\Database` and `Lucent\Model\*` are removed.** Radiant (`blueprintau/radiant`) is the ORM and database layer. Its facade is `BlueprintAU\Radiant\Database`.
- **`migration make {class}` is gone.** Replaced by `vendor/bin/lucent sync` — a diff-based schema synchronizer driven by your models' attributes. There are no migration files to author or track.
- **Route model binding is opt-in.** The `MODEL_BINDING=implicit` env gate is removed; binding happens only via the `#[Bind]` attribute.
- **The query cache is removed.** Radiant dropped query caching for security reasons.
- **Postgres is supported.** Drivers: `mysql`, `sqlite`, `pgsql`, `csv`.

## composer.json

Radiant is already a requirement of `blueprintau/lucent` — nothing to add. The `ext-mysqli` requirement is dropped; you need a PDO driver for your database instead (`pdo_mysql`, `pdo_sqlite`, `pdo_pgsql`).

```bash
composer update blueprintau/lucent
```

## Model Conversion

Old-style models used `Lucent\Model` with positional `#[Column]` attributes:

**Before:**

```php
use Lucent\Model\Model;
use Lucent\Model\Column;
use Lucent\Model\ColumnType;

class User extends Model
{
    #[Column(ColumnType::INT, primaryKey: true, autoIncrement: true)]
    public private(set) ?int $id;

    #[Column(ColumnType::VARCHAR, length: 255)]
    protected string $email;

    public function __construct(string $email)
    {
        $this->email = $email;
    }
}
```

**After:**

```php
use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\ColumnType;
use BlueprintAU\Radiant\Model;

class User extends Model
{
    #[Column(ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public private(set) ?int $id;

    #[Column(ColumnType::String, length: 255)]
    protected string $email;

    public function __construct(string $email)
    {
        $this->email = $email;
    }
}
```

Conversion rules:

- `ColumnType::INT` → omit the type (or `ColumnType::BigInt` for auto-increment PKs — **required** for SQLite).
- `ColumnType::VARCHAR` → `ColumnType::String` (or omit — inferred from the property type).
- `ColumnType::TEXT` → omit (or `ColumnType::Text`).
- `ColumnType::BOOLEAN` → `ColumnType::Boolean` (or omit).
- `ColumnType::DECIMAL` → `ColumnType::Decimal` with `precision:` + `scale:`.
- `ColumnType::UUID` → `ColumnType::Uuid`.
- Trait models: the old `SoftDelete` trait → Radiant's `SoftDeletes` trait; `Timestamps` → Radiant's `Timestamps`.
- Extended models (MTI) keep working — extend the parent model as before; Radiant splits the columns across tables automatically.

## Database Config

The `DB_*` environment variables are unchanged — Lucent maps them into Radiant's `DatabaseManager` config at boot:

```ini
DB_DRIVER=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=my_database
DB_USERNAME=root
DB_PASSWORD=secret
```

`DB_PORT` is cast to an integer (Radiant requires an int port). `DB_CHARSET` is supported for MySQL (default `utf8mb4`).

## Query API Mapping

| Old (`Lucent\Model\Model`) | New (Radiant) |
|---|---|
| `User::where('email', $value)` | `User::where('email', '=', $value)` — the operator is explicit |
| `->getFirst()` | `->first()` |
| `User::find($id)` | `User::find($id)` (unchanged) |
| `->count()` | `->count()` (unchanged) |
| `->sum('amount')` | `->sum('amount')` (unchanged) |
| `->like('email', 'gmail.com')` | `->where('email', 'like', '%gmail.com%')` |
| `->in('id', $ids)` | `->whereIn('id', $ids)` |
| `->compare('date', '<=', $v)` | `->where('date', '<=', $v)` |
| `$user->create()` | `$user->save()` |
| `$user->save()` | `$user->save()` (unchanged) |
| `$user->delete()` | `$user->delete()` (unchanged) |
| `Lucent\Database\Dataset` | Plain hydration — construct models directly, or `Model::fromRow($row)` |
| `Lucent\Database` (raw SQL) | `BlueprintAU\Radiant\Database` — `select()`, `statement()`, `affectingStatement()` |
| `Database::transaction(fn)` | `Database::sqlConnection()->transaction(fn)` |
| `Database::addConnection(...)` | `Database::manager()->addConnection(...)` |
| `Database::usingConnection(...)` | `Database::usingConnection(...)` (unchanged) |
| `Database::reset()` | `Database::manager()->flush()` |
| `Database::disabling('foreign_key_checks', fn)` | Not available — Radiant enforces FKs; drop tables in child-first order |

## Schema Workflow

**Before:** `migration make {class}` dropped and recreated the table from the model — destructive by design, and it required naming the model file path.

**After:** `vendor/bin/lucent sync` diffs the discovered models' desired schema against the live database and applies only the difference:

```bash
vendor/bin/lucent sync
```

- Non-destructive changes apply automatically.
- Destructive changes (drops, nullability tightening) prompt per change; `--force` skips prompts.
- Models are discovered automatically from your composer.json PSR-4 directories — no model path needed. `--filter`/`--exclude-filter` regexes narrow the set; `--dir=` overrides discovery.

First run on a fresh database creates every table. See [Schema](database/schema.md) for the full change vocabulary.

## One-Time `sync:legacy`

If your database was built by the old ORM, its tables are named after the model's short class name (`TestUser`). Radiant names them snake-cased plural (`test_users`). Run the one-time migration:

```bash
vendor/bin/lucent sync:legacy
```

- Renames each old-style table to its Radiant name — **data travels with the rename**.
- No column or data conversion is needed (old DDL remains valid under Radiant).
- Idempotent: a second run finds nothing to rename.
- **Deprecated** — it exists only for this migration and will be removed in a future release.

Run it once after upgrading, then run `sync` to bring the rest of the schema in line.

## Route Model Binding

`MODEL_BINDING=implicit` is removed. Binding is now opt-in per parameter via the `#[Bind]` attribute:

```php
use Lucent\Support\Attributes\Bind;

// Was: implicit binding with MODEL_BINDING=implicit
public function show(#[Bind] User $user): Response { ... }

// Bind by a non-PK column
public function show(#[Bind(resolve: 'slug')] Post $post): Response { ... }

// Scoped (the IDOR fix) — an invokable class-string
public function update(
    #[Bind(scope: \App\Security\TenantScope::class)]
    Post $post,
): Response { ... }
```

A Model type-hint **without** `#[Bind]` is never auto-resolved from the URL. See [Route Model Binding](route-model-binding.md) for the full attribute reference.

## Facades

Replace `Lucent\Database` usages with `BlueprintAU\Radiant\Database`:

```php
// Before
use Lucent\Database;
Database::select("SELECT * FROM users");

// After
use BlueprintAU\Radiant\Database;
Database::select("SELECT * FROM users");
```

`App::registerDatabaseDriver()` is removed — Radiant's connector registry (`DatabaseManager::extendConnector()`) is the extension point.

## Validation

`Model::uniqueConstraint()` is removed. Use Radiant's `#[Unique]` attribute on the model's column (enforced at the database level), or build the existence callable from a Radiant query:

```php
use Lucent\Validation\Constraints\Unique;

new Unique(fn (mixed $value) => User::where('email', '=', $value)->count() > 0);
```

## Upgrade Checklist

1. `composer update blueprintau/lucent`
2. Convert models to Radiant `#[Column]` style (see above).
3. Update query calls: `where(col, '=', val)`, `->first()`, `->save()`.
4. Replace `Lucent\Database` imports with `BlueprintAU\Radiant\Database`.
5. Run `vendor/bin/lucent sync:legacy` once (existing databases only).
6. Run `vendor/bin/lucent sync` to bring the schema in line.
7. Add `#[Bind]` to controller parameters that should bind from the route.
8. Remove `MODEL_BINDING`, `QUERY_CACHE*` env vars — they no longer exist.
