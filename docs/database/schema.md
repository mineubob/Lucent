[Home](../README.md)

# Schema Synchronization

Lucent's schema workflow is **diff-based**: your models declare the desired state, and the `sync` command computes the difference against the live database and applies it. There are no migration files to author or track — the models are the schema.

## How It Works

1. **Discover** — Lucent scans your app's PSR-4 directories for Radiant model classes.
2. **Declare** — each model's `#[Column]`/`#[Table]`/`#[Index]`/`#[ForeignKey]` attributes compile into a desired-state `Blueprint` via Radiant's `Blueprint::fromMetadata()`.
3. **Diff** — Radiant's `SchemaDiffer` compares the desired state against the live schema, producing ordered, classified `SchemaChange`s (creates first, then alters, drops last).
4. **Review** — the plan is printed with destructive changes flagged.
5. **Apply** — confirmed changes are applied under the `radiant:schema` lock, so the shown plan is exactly what gets applied.

## The Desired State

A model's attributes are the single source of truth:

```php
<?php

namespace App\Models;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\ForeignKey;
use BlueprintAU\Radiant\Attributes\Index;
use BlueprintAU\Radiant\Attributes\Table;
use BlueprintAU\Radiant\Attributes\Unique;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;

#[Table('posts')]
class Post extends Model
{
    #[Column(ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public private(set) ?int $id;

    #[Column(ColumnType::String, length: 255)]
    public string $title;

    #[Column(ColumnType::Text, nullable: true)]
    public ?string $body = null;

    #[Column(ColumnType::BigInt, foreign: 'users.id', onDelete: 'cascade')]
    public int $author_id;

    #[Column(ColumnType::Enum, values: ['draft', 'published'], default: 'draft')]
    public string $status;

    #[Unique(columns: ['title', 'author_id'])]
    #[Index(columns: ['status'])]
    public const CONSTRAINTS = null;
}
```

Running `vendor/bin/lucent sync` creates the `posts` table with all columns, the unique constraint, the index, and the foreign key — nothing to hand-write.

## Running Sync

```bash
vendor/bin/lucent sync
```

Output:

```
Planned schema changes:
    create table [posts]
    create table [users]
  ! drop table [legacy_table] — DESTRUCTIVE: data loss
Apply this destructive change? (yes/no)
```

- **Non-destructive changes** (creates, adds, renames) apply automatically.
- **Destructive changes** (drops, nullability tightening) prompt per change.
- **`--force`** skips all prompts.
- **`--dry-run`** displays the plan and exits without applying anything.
- **`--no-drop-tables`** makes the plan additive-only: tables no model declares (orphans, other tools' tables) are left untouched instead of offered for drop. Note this suppresses *table-level* drops only — a live column missing from the model still diffs as a destructive `DropColumn` and goes through the confirm gate.
- **Transactional apply** runs by default when the dialect supports transactional DDL (SQLite, PostgreSQL) — a mid-apply failure rolls the whole plan back. MySQL DDL auto-commits, so a warning is shown and the apply is non-transactional; opt out explicitly with `--no-transactional`.

### Filters

`--filter` and `--exclude-filter` are matched against fully-qualified class names; exclude wins. A pattern that is a valid regular expression is used verbatim; anything else is treated as a case-insensitive literal substring:

```bash
# Only the User model (regex)
vendor/bin/lucent sync --filter='/App\\Models\\User$/'

# Literal substring — no regex escaping needed
vendor/bin/lucent sync --filter=User

# Everything except legacy models
vendor/bin/lucent sync --exclude-filter='/Legacy/'

# Both — exclude wins
vendor/bin/lucent sync --filter='/App\\Models\\/' --exclude-filter='/Legacy/'
```

With filters active, excluded models' tables are **protected**: the differ itself never drops a protected table and never offers it as a rename target, so a filtered run cannot look orphaned to the differ and destroy an excluded model's table — not even through the rename-pairing path (two models sharing columns would otherwise be paired as a possible rename, moving the excluded table's data into the wrong table).

### Custom Directories

By default Lucent scans the PSR-4 directories registered with the Composer ClassLoader. Override with `--dir` (comma-separated):

```bash
vendor/bin/lucent sync --dir=app/Models,modules/Billing/Models
```

## Change Types

| Change | Destructive | Description |
|---|---|---|
| `create table` | No | New table from a model |
| `add column(s)` | No | New columns on an existing table |
| `rename column` | No | Declared via `#[Column(name: ...)]` — data travels with the rename |
| `rename table` | No | Declared via `#[Table]` when the old name exists — data travels with the rename |
| `modify column(s)` | Sometimes | Type/default drift; destructive when nullability tightens |
| `alter indexes` | No | Index option drift (partial predicate, NULLS NOT DISTINCT) |
| `add/drop foreign key` | No | Constraint shape changes |
| `add/drop check` | Advisory | CHECK expression drift is reported, never executed |
| `drop column(s)` | **Yes** | Columns removed from the model |
| `drop table` | **Yes** | Tables no longer declared by any model |

## Rename Detection

The differ flags **possible renames** rather than guessing: a create/drop pair whose columns overlap by ≥50% is annotated `POSSIBLE RENAME` in the plan. When `sync` sees a flagged pair it prompts:

```
POSSIBLE RENAME: [old_table] → [new_table]. Treat as a rename? (yes/no)
```

Confirming declares the rename on the blueprint (`renamedFrom`) and re-plans — **nothing touches the database until the plan is final**. The differ verifies the declaration and emits the real `RenameTable` change (data travels with it) instead of a drop + create. Each old table can only be claimed by one rename (highest overlap wins); competing creates stay as create + drop.

A declared rename and its column drift land in **one plan**: the differ emits the `RenameTable` change first, then diffs the desired columns against the old table's live shape and emits the follow-up `AddColumn`/`ModifyColumn`/`DropColumn` changes targeting the new name. Applying the plan leaves the schema fully in sync — a renamed column that also changed shape sequences `RenameColumn` first, then `ModifyColumn`.

With `--force` every suggestion is auto-accepted. Without a TTY the flagged pair is kept as-is (the plan shows the annotation and the destructive gate handles it).

To declare a rename deterministically instead of relying on the prompt, declare it (the new `#[Table]`/`#[Column(name:)]` value against the existing table) and re-run — the declared rename is verified against the live schema and data travels with it.

## The Schema Lock

The whole plan → display → apply flow runs under Radiant's `radiant:schema` cross-process lock (a named advisory lock on MySQL/Postgres; a file lock on SQLite). Two concurrent `sync` runs serialize — the second waits, then re-diffs against the updated schema.

## First Run

On a fresh database, `sync` creates every discovered model's table. On an existing database built by a pre-Radiant version of Lucent, run [`sync:legacy`](commandline.md) once first to rename the old-style tables.
