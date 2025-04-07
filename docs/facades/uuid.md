[Home](../../README.md)

# UUID in Lucent Framework

## Introduction

The Lucent Framework provides a robust and comprehensive UUID implementation through its `UUID` facade. Universal Unique Identifiers (UUIDs) are standardized identifiers that provide a reliable way to generate unique IDs without a centralized authority. This guide explains how to work with UUIDs in the Lucent Framework.

## Table of Contents

- [Overview](#overview)
- [Basic Usage](#basic-usage)
    - [Generating UUIDs](#generating-uuids)
    - [Validating UUIDs](#validating-uuids)
    - [Working with Different UUID Versions](#working-with-different-uuid-versions)
- [UUID Versions](#uuid-versions)
    - [Version 4 (Random)](#version-4-random)
    - [Version 7 (Time-ordered)](#version-7-time-ordered)
    - [Version 5 (Namespace)](#version-5-namespace)
    - [Nil UUID](#nil-uuid)
- [Advanced Features](#advanced-features)
    - [Binary Conversion](#binary-conversion)
    - [Version Detection](#version-detection)
    - [Working with Namespaces](#working-with-namespaces)
- [Database Integration](#database-integration)
    - [Using UUIDs as Primary Keys](#using-uuids-as-primary-keys)
    - [Storage Considerations](#storage-considerations)
- [Real-world Examples](#real-world-examples)
    - [Content Management System](#content-management-system)
- [Best Practices](#best-practices)

## Overview

UUIDs provide a way to generate identifiers that are practically guaranteed to be unique across systems and time. The Lucent Framework's `UUID` facade offers convenient methods for generating and working with UUIDs in your application.

Key features:
- Generation of various UUID versions (4, 5, 7)
- Validation of UUID strings
- Binary conversions for storage efficiency
- Namespace-based UUID generation
- Database integration

## Basic Usage

### Generating UUIDs

To generate a standard random UUID (version 4):

```php
use Lucent\Facades\UUID;

// Generate a random UUID (version 4)
$uuid = UUID::generate();
// Example output: "f47ac10b-58cc-4372-a567-0e02b2c3d479"
```

### Validating UUIDs

To check if a string is a valid UUID:

```php
use Lucent\Facades\UUID;

// Check if a string is a valid UUID
$isValid = UUID::isValid($uuid);

// Check if a string is a valid UUID of a specific version
$isValidV4 = UUID::isValid($uuid, 4);
```

### Working with Different UUID Versions

For time-ordered UUIDs (better for database indexing):

```php
// Generate a time-ordered UUID (version 7)
$timeBasedUuid = UUID::v7();
// Example output: "01889250-5608-79da-815b-ae9c1a15a9f3"
```

For namespace-based UUIDs:

```php
// Generate a namespace-based UUID (version 5)
$domainUuid = UUID::v5(UUID::$namespaces['dns'], 'example.com');
// Example output: "cfbff0d1-9375-5685-968c-48ce8b15ae17"
```

## UUID Versions

### Version 4 (Random)

UUID version 4 is generated using random numbers. It's suitable for most general-purpose applications where you need unique identifiers:

```php
$uuid = UUID::generate();
```

### Version 7 (Time-ordered)

UUID version 7 is time-ordered, making it ideal for database primary keys as they will sort chronologically:

```php
$uuid = UUID::v7();
```

Benefits of v7 UUIDs:
- Maintains the uniqueness guarantees of v4 UUIDs
- Adds chronological sorting which improves database indexing performance
- Follows the latest UUID specification

### Version 5 (Namespace)

UUID version 5 generates deterministic UUIDs based on a namespace and name using SHA-1 hashing:

```php
// Using a predefined namespace
$uuid = UUID::v5(UUID::$namespaces['dns'], 'example.com');

// Using a custom namespace UUID
$customNamespace = 'f47ac10b-58cc-4372-a567-0e02b2c3d479';
$uuid = UUID::v5($customNamespace, 'resource-name');
```

Benefits of v5 UUIDs:
- Deterministic - the same namespace and name always produce the same UUID
- Useful for mapping existing identifiers into the UUID space
- Good for generating consistent IDs for resources that need the same UUID across systems

### Nil UUID

The nil UUID is a special UUID where all bits are set to zero:

```php
$nilUuid = UUID::nil();
// Returns: "00000000-0000-0000-0000-000000000000"
```

Use cases for the nil UUID:
- Representing an uninitialized or default UUID
- Special sentinel value in application logic
- Signaling "no UUID" while maintaining UUID type safety

## Advanced Features

### Binary Conversion

For efficient database storage, convert UUIDs to binary format:

```php
// Convert string UUID to binary (16 bytes)
$binaryUuid = UUID::toBinary($uuid);

// Convert binary back to string format
$stringUuid = UUID::fromBinary($binaryUuid);
```

### Version Detection

Determine the version of a UUID:

```php
$version = UUID::getVersion($uuid);
// Returns: 4, 5, 7, etc. depending on the UUID version
```

### Working with Namespaces

Lucent provides predefined namespaces for common use cases:

```php
// Available namespaces
$dnsNamespace = UUID::$namespaces['dns'];    // For domain names
$urlNamespace = UUID::$namespaces['url'];    // For URLs
$oidNamespace = UUID::$namespaces['oid'];    // For OIDs
$x500Namespace = UUID::$namespaces['x500'];  // For X.500 DNs
```

## Database Integration

### Using UUIDs as Primary Keys

Example model using UUID as primary key:

```php
<?php

namespace App\Models;

use Lucent\Database\Attributes\DatabaseColumn;
use Lucent\Database\Dataset;
use Lucent\Facades\UUID;
use Lucent\Model;

class User extends Model
{
    #[DatabaseColumn([
        "PRIMARY_KEY" => true,
        "TYPE" => LUCENT_DB_VARCHAR,
        "LENGTH" => 36,
        "ALLOW_NULL" => false,
        "AUTO_INCREMENT" => false,
    ])]
    protected string $id;
    
    #[DatabaseColumn([
        "TYPE" => LUCENT_DB_VARCHAR,
        "LENGTH" => 255,
        "ALLOW_NULL" => false
    ])]
    protected string $name;
    
    #[DatabaseColumn([
        "TYPE" => LUCENT_DB_VARCHAR,
        "LENGTH" => 255,
        "ALLOW_NULL" => false,
        "UNIQUE" => true
    ])]
    protected string $email;
    
    public function __construct(Dataset $data)
    {
           $this->id = $data->get("id", UUID::v7());
           $this->name = $data->get("name");
           $this->email = $data->get("email");
    }
    
    // Getters and setters...
}
```

### Storage Considerations

When using UUIDs in databases, consider these options:

1. **String Storage (VARCHAR)**:
    - Easy to read and debug
    - Takes 36 characters (including hyphens)
    - Less efficient for indexing

   ```sql
   CREATE TABLE users (
       id VARCHAR(36) PRIMARY KEY,
       name VARCHAR(255) NOT NULL,
       email VARCHAR(255) NOT NULL
   );
   ```

2. **Binary Storage (BINARY)**:
    - More efficient storage (16 bytes)
    - Better indexing performance
    - Harder to read in database queries

   ```sql
   CREATE TABLE users (
       id BINARY(16) PRIMARY KEY,
       name VARCHAR(255) NOT NULL,
       email VARCHAR(255) NOT NULL
   );
   ```

   With binary storage, use the binary conversion methods:

   ```php
   // When saving
   $binaryId = UUID::toBinary($uuid);
   
   // When retrieving
   $uuid = UUID::fromBinary($binaryData);
   ```

## Real-world Examples

### Content Management System

```php
<?php

namespace App\Models;

use Lucent\Database\Attributes\DatabaseColumn;
use Lucent\Database\Dataset;
use Lucent\Facades\UUID;
use Lucent\Model;

class Article extends Model
{
    #[DatabaseColumn([
        "PRIMARY_KEY" => true,
        "TYPE" => LUCENT_DB_VARCHAR,
        "LENGTH" => 36,
        "ALLOW_NULL" => false,
        "AUTO_INCREMENT" => false,
    ])]
    protected string $id;
    
    #[DatabaseColumn([
        "TYPE" => LUCENT_DB_VARCHAR,
        "LENGTH" => 36,
        "ALLOW_NULL" => false,
    ])]
    protected string $author_id;
    
    #[DatabaseColumn([
        "TYPE" => LUCENT_DB_VARCHAR,
        "LENGTH" => 200,
        "ALLOW_NULL" => false
    ])]
    protected string $title;
    
    #[DatabaseColumn([
        "TYPE" => LUCENT_DB_TEXT,
        "ALLOW_NULL" => false
    ])]
    protected string $content;
    
    #[DatabaseColumn([
        "TYPE" => LUCENT_DB_TIMESTAMP,
        "DEFAULT" => LUCENT_DB_DEFAULT_CURRENT_TIMESTAMP,
        "ALLOW_NULL" => false
    ])]
    protected string $created_at;
    
    public function __construct(Dataset $data)
    {
        $this->id = $data->get("id", UUID::v7());
        $this->author_id = $data->get("author_id");
        $this->title = $data->get("title");
        $this->content = $data->get("content");
        $this->created_at = $data->get("created_at");
    }
    
}
```

## Best Practices

1. **Choose the Right Version**:
    - Use UUID v4 (random) for general purposes
    - Use UUID v7 (time-ordered) for database primary keys
    - Use UUID v5 (namespace) for deterministic IDs or mapping existing identifiers

2. **Binary Storage**:
    - Consider storing UUIDs in binary format in databases for efficiency
    - Convert between binary and string formats as needed using `toBinary()` and `fromBinary()`

3. **Security Considerations**:
    - Don't use predictable UUIDs for security-sensitive contexts
    - For API keys or tokens, consider additional randomization or hashing

4. **Version Standardization**:
    - Standardize on a specific UUID version throughout your application
    - Document your UUID version choice in your codebase

5. **Database Indexing**:
    - When using UUIDs as primary keys, ensure proper indexing
    - Consider the performance implications, especially with string-format UUIDs

6. **Validation**:
    - Always validate UUIDs received from external sources
    - Use the `isValid()` method with the expected version

7. **Error Handling**:
    - Handle invalid UUID inputs gracefully
    - Provide meaningful error messages when UUID validation fails

8. **Documentation**:
    - Document the UUID format you use in API specifications
    - Include version information when exposing UUIDs in your API