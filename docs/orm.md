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
  - [Basic Queries](#basic-queries)
  - [Logical Operators](#logical-operators)
  - [Like Queries](#like-queries)
  - [Pagination](#pagination)
- [Model Relationships](#model-relationships)
- [Model Traits](#model-traits)
  - [Creating Traits](#creating-traits)
  - [Using Traits](#using-traits)
  - [Trait Query Scope](#trait-query-scope)
  - [Example: SoftDelete Trait](#example-softdelete-trait)

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

### Basic Queries

Lucent provides a fluent interface for building queries:

```php
// Basic where clause
$articles = Article::where('published', true)->get();

// Chain multiple conditions (using AND by default)
$articles = Article::where('published', true)
    ->where('category_id', 3)
    ->get();

// Count records
$count = Article::where('published', true)->count();
```

### Logical Operators

Lucent supports both AND and OR logical operators in queries:

```php
// Default behavior uses AND
$articles = Article::where('published', true)
    ->where('category_id', 3)
    ->get();
    
// Explicitly specify AND or OR for each condition
$articles = Article::where('published', true)
    ->where('category_id', 3, 'AND')  // Explicit AND
    ->where('featured', true, 'OR')   // Use OR logic for this condition
    ->get();
    
// Convenience methods for OR conditions
$articles = Article::where('published', true)
    ->orWhere('featured', true)       // Equivalent to ->where('featured', true, 'OR')
    ->get();
    
// Complex query with mixed operators
$articles = Article::where('status', 'active')        // AND (default)
    ->where('category_id', 5)                       // AND (default)
    ->orWhere('featured', true)                     // OR
    ->orWhere('popular', true)                      // OR
    ->get();
    
// The query above is equivalent to SQL:
// WHERE status = 'active' AND category_id = 5 OR featured = true OR popular = true
```

### Like Queries

You can use LIKE conditions for pattern matching, also with AND/OR support:

```php
// Basic LIKE query (default AND)
$articles = Article::like('title', 'Lucent')
    ->get();
    
// Combining LIKE with WHERE
$articles = Article::where('published', true)
    ->like('title', 'Lucent')
    ->get();
    
// Using OR with LIKE
$articles = Article::like('title', 'Lucent')
    ->orLike('content', 'framework')
    ->get();
    
// Explicit OR operator
$articles = Article::like('title', 'Lucent')
    ->like('content', 'framework', 'OR')
    ->get();
    
// Complex query mixing WHERE and LIKE with different operators
$articles = Article::where('published', true)
    ->where('category_id', 3)
    ->like('title', 'Lucent')
    ->orLike('content', 'framework')
    ->get();
```

### Pagination

Use limit and offset for pagination:

```php
// Get first page (10 items)
$page1 = Article::where('published', true)
    ->limit(10)
    ->offset(0)  // Can be omitted for first page
    ->get();

// Get second page (next 10 items)
$page2 = Article::where('published', true)
    ->limit(10)
    ->offset(10)
    ->get();
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

## Model Traits

Lucent ORM now supports using PHP traits in models with enhanced functionality for traits that define database columns and behavior. This allows you to encapsulate reusable model functionality like soft deletion, timestamping, or user tracking across multiple models.

### Creating Traits

Traits in Lucent can define database columns and methods that should be included in multiple models:

```php
<?php

namespace App\Models;

use Lucent\Database\Attributes\DatabaseColumn;

trait Timestampable
{
    #[DatabaseColumn([
        "TYPE" => LUCENT_DB_TIMESTAMP,
        "ALLOW_NULL" => true
    ])]
    public private(set) ?string $created_at = null;

    #[DatabaseColumn([
        "TYPE" => LUCENT_DB_TIMESTAMP,
        "ALLOW_NULL" => true
    ])]
    public private(set) ?string $updated_at = null;
    
    public function setCreatedAt(): void
    {
        $this->created_at = date('Y-m-d H:i:s');
    }
    
    public function setUpdatedAt(): void
    {
        $this->updated_at = date('Y-m-d H:i:s');
    }
}
```

### Using Traits

To use a trait in your model, simply include it with the PHP `use` statement. **Important:** You must manually initialize the trait properties in your constructor from the Dataset:

```php
<?php

namespace App\Models;

use Lucent\Database\Attributes\DatabaseColumn;
use Lucent\Database\Dataset;
use Lucent\Model;
use App\Models\Timestampable;

class Article extends Model
{
    use Timestampable;

    #[DatabaseColumn([
        "PRIMARY_KEY" => true,
        "TYPE" => LUCENT_DB_INT,
        "ALLOW_NULL" => false,
        "AUTO_INCREMENT" => true
    ])]
    public private(set) ?int $id;

    #[DatabaseColumn([
        "TYPE" => LUCENT_DB_VARCHAR,
        "ALLOW_NULL" => false
    ])]
    protected string $title;
    
    // ... other properties and methods

    public function __construct(Dataset $dataset)
    {
        $this->id = $dataset->get("id");
        $this->title = $dataset->get("title");
        
        // You must initialize trait properties from the dataset
        // Lucent won't do this automatically
        $this->created_at = $dataset->get("created_at");
        $this->updated_at = $dataset->get("updated_at");
    }
    
    // Override the create() method to set timestamps
    public function create(): bool
    {
        $this->setCreatedAt();
        $this->setUpdatedAt();
        return parent::create();
    }
    
    // Override the save() method to update the updated_at timestamp
    public function save(string $identifier = "id"): bool
    {
        $this->setUpdatedAt();
        return parent::save($identifier);
    }
}
```

### Trait Query Scope

One of the powerful features of Lucent's trait support is the ability to register global query scopes for models that use specific traits. This allows you to automatically apply certain query conditions whenever a model with that trait is queried.

To register a trait condition:

```php
use Lucent\ModelCollection;

// Register a condition for models using the SoftDelete trait
ModelCollection::registerTraitCondition(
    \App\Models\SoftDelete::class,  // The trait class
    'deleted_at',                   // The column to apply the condition to
    null                           // The value to check for (null = include only non-deleted records)
);
```

Now, any query on a model that uses the `SoftDelete` trait will automatically include a `WHERE deleted_at IS NULL` condition, effectively filtering out soft-deleted records by default.

### Example: SoftDelete Trait

Here's a complete example of a SoftDelete trait implementation:

```php
<?php

namespace App\Models;

use Lucent\Database\Attributes\DatabaseColumn;
use Lucent\Database\Dataset;
use Lucent\Model;

trait SoftDelete
{
    #[DatabaseColumn([
        "TYPE" => LUCENT_DB_INT,
        "ALLOW_NULL" => true
    ])]
    public private(set) ?int $deleted_at = null;

    /**
     * Override the base delete method with a soft delete implementation
     *
     * @param mixed $propertyName The primary key property name
     * @return bool Success
     */
    public function delete($propertyName = "id"): bool
    {
        return $this->softDelete($propertyName);
    }

    /**
     * Delete the model by setting the deleted_at timestamp
     *
     * @param string $propertyName The primary key property name
     * @return bool Success
     */
    public function softDelete(string $propertyName = "id"): bool
    {
        $this->deleted_at = time();
        return $this->save($propertyName);
    }

    /**
     * Restore a soft deleted model
     *
     * @return bool Success
     */
    public function restore(): bool
    {
        $this->deleted_at = null;
        return $this->save();
    }
}
```

Using the SoftDelete trait in a model:

```php
<?php

namespace App\Models;

use Lucent\Database\Attributes\DatabaseColumn;
use Lucent\Database\Dataset;
use Lucent\Model;
use App\Models\SoftDelete;

class TestUser extends Model
{
    use SoftDelete;

    #[DatabaseColumn([
        "PRIMARY_KEY" => true,
        "TYPE" => LUCENT_DB_INT,
        "ALLOW_NULL" => false,
        "AUTO_INCREMENT" => true
    ])]
    public private(set) ?int $id;

    #[DatabaseColumn([
        "TYPE" => LUCENT_DB_VARCHAR,
        "ALLOW_NULL" => false
    ])]
    protected string $email;

    // ... other properties

    public function __construct(Dataset $dataset)
    {
        $this->id = $dataset->get("id", -1);
        $this->email = $dataset->get("email");
        
        // Important: You must manually initialize trait properties
        // from the dataset in your constructor
        $this->deleted_at = $dataset->get("deleted_at");
    }
}
```

Automatically applying the soft delete condition:

```php
// Register the trait condition once (typically in your application bootstrap)
ModelCollection::registerTraitCondition(
    App\Models\SoftDelete::class,
    'deleted_at',
    null
);

// Now queries will automatically exclude soft-deleted records
$users = TestUser::where('email', 'like', '@example.com')->get();

// To include soft-deleted records, you would need to override the condition
$allUsers = TestUser::where('deleted_at', 'IS NOT NULL', 'OR')
    ->orWhere('deleted_at', null)
    ->get();
```

---

For more information on using Lucent's ORM, check the [full documentation](../README.md).