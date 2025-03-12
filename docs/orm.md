[Home](../README.md)

# Lucent ORM Guide

Lucent provides a powerful and intuitive Object-Relational Mapping (ORM) system for interacting with your database. This guide will explain the key features and usage patterns of the Lucent ORM.

## Table of Contents

- [Model Basics](#model-basics)
- [Defining Models](#defining-models)
- [Database Columns](#database-columns)
- [CRUD Operations](#crud-operations)
    - [Creating Records](#creating-records)
    - [Reading Records](#reading-records)
    - [Updating Records](#updating-records)
    - [Deleting Records](#deleting-records)
- [Query Building](#query-building)
- [Model Relationships](#model-relationships)

## Model Basics

Lucent's ORM centers around the `Model` class which provides a simple and elegant way to interact with database tables. Each model represents a table in your database, and each instance of a model represents a row in that table.

```php
use Lucent\Model;
use Lucent\Database\Dataset;

class Article extends Model
{
    // Model implementation
    
    public function __construct(Dataset $dataset)
    {
        // Initialize properties from dataset
    }
}
```

> **Note**: All model instances must be constructed with a `Dataset` object that contains the property values. The `Dataset` is used to populate model properties and provides a consistent way to handle data throughout the ORM.

## Defining Models

To define a model in Lucent, you create a class that extends `Lucent\Model`. Property attributes define the database schema:

```php
<?php

namespace App\Models;

use Lucent\Database\Attributes\DatabaseColumn;
use Lucent\Database\Dataset;
use Lucent\Model;

class Article extends Model
{
    #[DatabaseColumn([
        "NAME" => "id",
        "ALLOW_NULL" => false,
        "TYPE" => LUCENT_DB_INT,
        "PRIMARY_KEY" => true,
        "AUTO_INCREMENT" => true
    ])]
    public int $id;

    #[DatabaseColumn([
        "NAME" => "title",
        "ALLOW_NULL" => false,
        "TYPE" => LUCENT_DB_VARCHAR,
        "LENGTH" => 200
    ])]
    public string $title;

    #[DatabaseColumn([
        "NAME" => "content",
        "ALLOW_NULL" => false,
        "TYPE" => LUCENT_DB_TEXT
    ])]
    public string $content;

    #[DatabaseColumn([
        "NAME" => "published",
        "ALLOW_NULL" => false,
        "TYPE" => LUCENT_DB_BOOLEAN,
        "DEFAULT" => 0
    ])]
    public bool $published = false;
    
    /**
     * Constructor that accepts a Dataset
     */
    public function __construct(Dataset $dataset)
    {
        $this->id = $dataset->get("id");
        $this->title = $dataset->get("title");
        $this->content = $dataset->get("content");
        $this->published = $dataset->get("published", false);
    }
}
```

## Database Columns

The `DatabaseColumn` attribute is used to define column properties:

| Property | Description |
|---|---|
| `NAME` | Column name in the database table |
| `ALLOW_NULL` | Whether the column can contain NULL values |
| `TYPE` | Data type (constants like LUCENT_DB_INT, LUCENT_DB_VARCHAR, etc.) |
| `PRIMARY_KEY` | Whether this column is the primary key |
| `AUTO_INCREMENT` | Whether this column auto-increments (for IDs) |
| `LENGTH` | Maximum length for string-type columns |
| `DEFAULT` | Default value for the column |
| `UNIQUE` | Whether values must be unique |
| `REFERENCES` | Foreign key reference |

> **Important**: Each model must have exactly one property marked with `PRIMARY_KEY` set to `true`. This column will be used by default for save() and delete() operations, but you can specify a different column name as an argument if needed.

## CRUD Operations

### Creating Records

To create a new database record, first create an instance with a Dataset containing your data:

```php
// Create a Dataset with your record data
$dataset = new Dataset([
    "title" => "Getting Started with Lucent",
    "content" => "Lucent is a powerful PHP framework...",
    "published" => true
]);

// Create model instance with the dataset
$article = new Article($dataset);

// Save to database
$article->create();
```

### Reading Records

Fetch records with static query methods:

```php
// Get first matching record
$article = Article::where('id', 5)->getFirst();

// Get all matching records
$articles = Article::where('published', true)->get();

// Limit results
$recentArticles = Article::where('published', true)
    ->limit(10)
    ->get();

// Pagination
$page2Articles = Article::where('published', true)
    ->limit(10)
    ->offset(10)
    ->get();
```

### Updating Records

Update an existing record:

```php
$article = Article::where('id', 5)->getFirst();
$article->title = "Updated Title";

// Save using default 'id' primary key
$article->save();

// Or if your primary key column has a different name
$article->save('article_id');
```

### Deleting Records

Delete a record:

```php
$article = Article::where('id', 5)->getFirst();

// Delete using default 'id' primary key
$article->delete();

// Or if your primary key column has a different name
$article->delete('article_id');
```

## Query Building

Lucent provides a fluent interface for building queries:

```php
// Basic where clause
$articles = Article::where('published', true)->get();

// Chain multiple conditions
$articles = Article::where('published', true)
    ->where('category_id', 3)
    ->get();

// Limit, offset for pagination
$articles = Article::where('published', true)
    ->limit(10)
    ->offset(20)
    ->get();

// Get count
$count = Article::where('published', true)->count();
```

## Model Relationships

Lucent handles model inheritance for relationships. For example, a specialized user type:

```php
<?php

namespace App\Models;

use Lucent\Database\Attributes\DatabaseColumn;
use Lucent\Database\Dataset;
use App\Models\TestUser;

class Admin extends TestUser
{
    #[DatabaseColumn([
        "TYPE" => LUCENT_DB_BOOLEAN,
        "ALLOW_NULL" => false,
        "DEFAULT" => false
    ])]
    public private(set) bool $can_reset_passwords;

    #[DatabaseColumn([
        "TYPE" => LUCENT_DB_BOOLEAN,
        "ALLOW_NULL" => false,
        "DEFAULT" => false,
    ])]
    public private(set) bool $can_lock_accounts;

    #[DatabaseColumn([
        "TYPE" => LUCENT_DB_VARCHAR,
        "ALLOW_NULL" => true,
    ])]
    public private(set) ?string $notes;


    public function __construct(Dataset $dataset){
        // Call parent constructor first to initialize inherited properties
        parent::__construct($dataset);
        
        // Then initialize this model's properties from the dataset
        $this->can_reset_passwords = $dataset->get("can_reset_passwords", false);
        $this->can_lock_accounts = $dataset->get("can_lock_accounts", false);
        $this->notes = $dataset->get("notes");
    }
    
    public function setNotes(string $notes): void
    {
        $this->notes = $notes;
    }
}
```

When working with extended models, Lucent will manage foreign keys and joins automatically.

## Related Topics

For information on how Lucent's ORM integrates with the routing system, see the [Route Model Binding](route-model-binding.md) documentation.

---

For more information on using Lucent's ORM, check the [full documentation](../README.md).
