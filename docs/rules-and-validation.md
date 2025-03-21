[Home](../README.md)

# Lucent Framework Validation Guide

The Lucent Framework provides a powerful and flexible validation system through its `Rule` classes. This guide will help you understand how to implement validation in your application to ensure data integrity and security.

## Table of Contents

- [Overview](#overview)
- [Basic Concepts](#basic-concepts)
- [Creating Validation Rules](#creating-validation-rules)
- [Using Validation Rules](#using-validation-rules)
- [Available Validation Rules](#available-validation-rules)
- [Negated Rules](#negated-rules)
- [Nullable Fields](#nullable-fields)
- [Custom Regex Patterns](#custom-regex-patterns)
- [Advanced Usage](#advanced-usage)
- [Real-world Example: Contact Form](#real-world-example-contact-form)
- [Real-world Example: Article Creation](#real-world-example-article-creation)
- [Best Practices](#best-practices)

## Overview

The validation system in Lucent Framework is built around the abstract `Rule` class and works by defining sets of constraints that incoming data must satisfy. When validation fails, meaningful error messages are automatically generated to help users correct their input.

## Basic Concepts

### The Rule Class

At the core of the validation system is the abstract `Rule` class which all your rule definitions should extend. Each rule class defines:

- A set of fields to validate
- Constraints to apply to each field
- Error messages to return when validation fails

### Validation Process

The standard validation flow is:

1. Create a Rule class with validation criteria
2. Apply the Rule to incoming request data
3. Check if validation passed or failed
4. Access any validation errors if needed

## Creating Validation Rules

### Basic Rule Structure

To create a validation rule, extend the `Rule` class and implement the `setup()` method:

```php
<?php

namespace App\Validation;

use Lucent\Validation\Rule;

class UserRule extends Rule
{
    public function setup(): array
    {
        return [
            'name' => [
                'min:2',
                'max:50'
            ],
            'email' => [
                'regex:email'
            ],
            'password' => [
                'min:8',
                'regex:password'
            ]
        ];
    }
}
```

The `setup()` method should return an associative array where:
- Keys are the field names to validate
- Values are arrays of validation constraints

## Using Validation Rules

### In Controllers

To validate incoming requests in your controllers:

```php
<?php

namespace App\Controllers;

use App\Validation\UserRule;
use Lucent\Http\Request;
use Lucent\Http\JsonResponse;

class UserController
{
    public function register(Request $request): JsonResponse
    {
        // Validate the request data
        if (!$request->validate(UserRule::class)) {
            return new JsonResponse()
                ->setOutcome(false)
                ->setStatusCode(400)
                ->setMessage("Validation failed")
                ->addErrors($request->getValidationErrors());
        }
        
        // If validation passed, continue with registration...
        // ...
        
        return new JsonResponse()
            ->setOutcome(true)
            ->setMessage("User registered successfully");
    }
}
```

## Available Validation Rules

The Lucent Framework provides these built-in validation rules:

> **Important Note**: For the `unique` and `!unique` validation rules, the framework automatically uses the input field name as the column name in the database table. For example, if you validate a field named `email` with `unique:users`, it will check if the submitted value exists in the `email` column of the `users` table.

| Rule | Description | Example |
|------|-------------|---------|
| `min:{value}` | Ensures a string's length is at least the specified value | `'min:2'` |
| `max:{value}` | Ensures a string's length is at most the specified value | `'max:100'` |
| `min_num:{value}` | Ensures a numeric value is at least the specified value | `'min_num:1'` |
| `max_num:{value}` | Ensures a numeric value is at most the specified value | `'max_num:100'` |
| `regex:{pattern}` | Validates using a registered regex pattern | `'regex:email'` |
| `same:{field}` | Ensures the value matches another field's value | `'same:password'` |
| `unique:{table}` | Ensures the value doesn't exist in the specified table column | `'unique:users'` |
| `!unique:{table}` | Ensures the value does exist in the specified table column | `'!unique:users'` |
| `nullable` | Allows a field to be empty or null | `'nullable'` |

## Negated Rules

You can negate any validation rule by prefixing it with `!`. This inverts the expected outcome of the validation.

```php
// Validation passes if the value is NOT an email format
'email' => ['!regex:email']

// Validation passes if the length is NOT at least 8 characters
'password' => ['!min:8']

// Validation passes if the value does NOT match the other field
'password_confirm' => ['!same:password']
```

Note that `!unique` is a special case that was already documented separately since it's a common use case.

## Nullable Fields

The `nullable` rule allows a field to be empty or null without failing validation. When combined with other rules, the field becomes optional - if a value is provided, it must meet the other rules, but empty values will pass validation.

```php
// Optional email that must be valid if provided
'email' => ['nullable', 'regex:email']

// Optional name with length constraints if provided
'name' => ['nullable', 'min:2', 'max:50']

// Optional birthdate with date format validation if provided
'birthdate' => ['nullable', 'regex:date']
```

The `nullable` rule can be placed anywhere in the rules array - it doesn't have to be first. If a field is both nullable and empty, all other validation rules for that field will be skipped.

## Custom Regex Patterns

### Local Regex Patterns

You can define custom regex patterns within your rule class:

```php
<?php

namespace App\Validation;

use Lucent\Validation\Rule;

class ProductRule extends Rule
{
    public function setup(): array
    {
        // Add a custom regex pattern for SKU validation
        $this->addRegexPattern(
            "sku_format",
            '/^[A-Z]{3}-\d{4}$/', 
            "SKU must be in format XXX-0000"
        );
        
        return [
            'sku' => [
                'regex:sku_format'
            ],
            'name' => [
                'min:3',
                'max:100'
            ]
        ];
    }
}
```

The `addRegexPattern` method takes three parameters:
1. Pattern name - Used to reference the pattern in rules
2. Regex pattern - The actual regular expression
3. Error message (optional) - Custom error message when validation fails

### Global Regex Patterns

For patterns you need to use across multiple rule classes, you can define global patterns using the `Regex` facade:

```php
<?php

use Lucent\Facades\Regex;

// In a service provider or bootstrap file
Regex::set("phone_number", '/^\+?[1-9]\d{1,14}$/');
```

These global patterns can then be used in any rule class:

```php
<?php

namespace App\Validation;

use Lucent\Validation\Rule;

class ContactRule extends Rule
{
    public function setup(): array
    {
        return [
            'phone' => [
                'regex:phone_number'
            ]
        ];
    }
}
```

### Built-in Regex Patterns

The framework includes these built-in regex patterns:

| Pattern Name | Validates | Format Example |
|--------------|-----------|----------------|
| `email` | Email addresses | test@example.com |
| `password` | Password complexity (requires one lowercase, one uppercase, min 8 chars) | Password123 |
| `date` | Date in YYYY-MM-DD format | 2023-12-31 |
| `url` | Web addresses | https://example.com |
| `phone` | International phone numbers | +1234567890 |
| `ip` | IPv4 addresses | 192.168.0.1 |
| `hex_color` | HEX color codes | #FFF or #FFFFFF |
| `uuid` | UUID v1-v5 format | 123e4567-e89b-12d3-a456-426614174000 |
| `alpha` | Letters only | AbCdEf |
| `alphanumeric` | Letters and numbers only | Abc123 |

### Validation Rule Examples

```php
// Email validation
'email' => ['regex:email']

// Password validation with confirmation
'password' => ['regex:password'],
'password_confirm' => ['same:@password']

// Date validation
'birth_date' => ['regex:date']

// URL validation
'website' => ['nullable', 'regex:url']

// Phone number validation
'phone' => ['nullable', 'regex:phone']

// Numeric range validation
'age' => ['min_num:18', 'max_num:120']

// Color picker validation
'theme_color' => ['regex:hex_color']

// UUID validation (for API tokens, etc.)
'token_id' => ['regex:uuid']

// Text-only validation
'first_name' => ['alpha', 'min:2', 'max:50']

// Alphanumeric validation
'username' => ['unique:users', 'alphanumeric', 'min:3', 'max:20']

// Existence validation - checks the 'role_id' column in the 'roles' table
'role_id' => ['!unique:roles']

// Optional field validation
'middle_name' => ['nullable', 'alpha', 'min:2', 'max:50']
```

## Advanced Usage

### Custom Validation Methods

For more complex validation needs, you can extend the `Rule` class and add custom validation methods. Here's how you might implement a ZIP code validator:

```php
<?php

namespace App\Validation;

use Lucent\Validation\Rule;

class AddressRule extends Rule
{
    public function setup(): array
    {
        return [
            'address_line1' => ['min:5', 'max:100'],
            'city' => ['min:2', 'max:50'],
            'zip_code' => ['validate_zip_code']
        ];
    }
    
    protected function validate_zip_code($value): bool
    {
        // US zip code validation logic
        return preg_match('/^\d{5}(-\d{4})?$/', $value);
    }
}
```

## Real-world Example: Contact Form

Let's build a complete example for a contact form with validation:

### 1. Define the Validation Rule

```php
<?php

namespace App\Validation;

use Lucent\Validation\Rule;

class ContactFormRule extends Rule
{
    public function setup(): array
    {
        return [
            'name' => [
                'min:2',
                'max:100'
            ],
            'email' => [
                'regex:email'
            ],
            'phone' => [
                'nullable',
                'min:10',
                'max:15'
            ],
            'subject' => [
                'min:5',
                'max:200'
            ],
            'message' => [
                'min:20',
                'max:2000'
            ]
        ];
    }
}
```

### 2. Implement the Controller

```php
<?php

namespace App\Controllers;

use App\Validation\ContactFormRule;
use Lucent\Http\Request;
use Lucent\Http\JsonResponse;
use App\Models\ContactMessage;

class ContactController
{
    public function submit(Request $request): JsonResponse
    {
        // Validate the form input
        if (!$request->validate(ContactFormRule::class)) {
            return new JsonResponse()
                ->setOutcome(false)
                ->setStatusCode(400)
                ->setMessage("Please correct the form errors")
                ->addErrors($request->getValidationErrors());
        }
        
        // Create a new contact message
        $message = new ContactMessage($request->dataset());
        $message->create();
        
        // Return success response
        return new JsonResponse()
            ->setOutcome(true)
            ->setMessage("Thank you for your message. We'll respond shortly.");
    }
}
```

### 3. Define Routes

```php
<?php
// routes.php

use Lucent\Facades\Route;
use App\Controllers\ContactController;

Route::rest()->group('api')
    ->defaultController(ContactController::class)
    ->post('contact', 'submit');
```

## Real-world Example: Article Creation

Let's build another example for validating article creation in a content management system:

### 1. Define the Validation Rule

```php
<?php

namespace App\Validation;

use Lucent\Validation\Rule;

class ArticleRule extends Rule
{
    public function setup(): array
    {
        // Define custom regex for slugs
        $this->addRegexPattern(
            "slug_format", 
            '/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
            "Slug must contain only lowercase letters, numbers, and hyphens"
        );
        
        return [
            'title' => [
                'min:5',
                'max:200'
            ],
            'slug' => [
                'min:3',
                'max:100',
                'regex:slug_format',
                'unique:articles'  // Ensures the slug is unique in the articles table
            ],
            'content' => [
                'min:100',  // Require at least 100 characters of content
            ],
            'category_id' => [
                '!unique:categories'  // Ensure the category exists in the categories table
            ],
            'tags' => [
                'nullable',
                'min:2',  // At least 2 characters for tags if provided
                'max:255'  // Maximum length for all tags if provided
            ],
            'status' => [
                'validate_status'  // Custom validation method for status
            ]
        ];
    }
    
    // Custom validation method for article status
    protected function validate_status($value): bool
    {
        // Valid statuses are: draft, published, archived
        $validStatuses = ['draft', 'published', 'archived'];
        return in_array(strtolower($value), $validStatuses);
    }
}
```

### 2. Implement the Controller

```php
<?php

namespace App\Controllers;

use App\Validation\ArticleRule;
use Lucent\Http\Request;
use Lucent\Http\JsonResponse;
use App\Models\Article;

class ArticleController
{
    public function create(Request $request): JsonResponse
    {
        // Validate the article data
        if (!$request->validate(ArticleRule::class)) {
            return new JsonResponse()
                ->setOutcome(false)
                ->setStatusCode(400)
                ->setMessage("Please fix the errors in your article")
                ->addErrors($request->getValidationErrors());
        }
        
        // If we get here, validation passed
        // Create a dataset from the request and add the author_id
        $data = $request->dataset();
        $data->set('author_id', $request->getUrlVariable('user_id'));
        
        // Create the article with the dataset
        $article = new Article($data);
        
        // Save the article
        if (!$article->create()) {
            return new JsonResponse()
                ->setOutcome(false)
                ->setStatusCode(500)
                ->setMessage("Failed to create the article");
        }
        
        // Return success response with the created article
        return new JsonResponse()
            ->setOutcome(true)
            ->setMessage("Article created successfully")
            ->addContent('article', [
                'id' => $article->id,
                'title' => $article->title,
                'slug' => $article->slug,
                'status' => $article->status
            ]);
    }
}
```

### 3. Define Routes

```php
<?php
// routes.php

use Lucent\Facades\Route;
use App\Controllers\ArticleController;

Route::rest()->group('api')
    ->defaultController(ArticleController::class)
    ->post('users/{user_id}/articles', 'create');
```

This example demonstrates several important validation features:

1. Using a custom regex pattern for slug validation
2. Using `unique` validation to ensure article slugs don't conflict
3. Using `!unique` validation to verify a category exists
4. Using `nullable` to make tags optional
5. Implementing a custom validation method (`validate_status`)
6. Combining URL variables with validated form input
7. Returning different responses based on validation outcome

## Best Practices

1. **Separate Concerns**: Create different rule classes for different aspects of your application, rather than a single large validation class.

2. **Descriptive Names**: Give your validation rules clear, descriptive names that indicate their purpose (e.g., `UserRegistrationRule`, `ProductCreationRule`).

3. **Reuse Rules**: Avoid duplicating validation logic by creating shared rule classes that can be extended.

4. **Document Your Rules**: Create clear documentation explaining your validation requirements, especially for complex custom rules.

5. **Security First**: Always validate data on the server-side, even if you have client-side validation.

6. **Custom Error Messages**: For complex validation requirements, consider implementing custom error messages that are more descriptive.

7. **Use Nullable Properly**: Use the `nullable` rule for optional fields rather than adding complex conditional logic.

8. **Field References**: When referencing other fields (like in the `same` rule), use the `@` prefix (e.g., `same:@password`).

9. **Centralize Regex Patterns**: Store frequently used regex patterns in a central location using the Regex facade.

10. **Validation Strategy**: Use negated rules (`!`) when checking for the absence of a condition is more logical than checking for its presence.

---

With this guide, you should be well-equipped to implement robust validation in your Lucent Framework application. Validation is a crucial part of building secure, reliable web applications, and Lucent's validation system gives you the tools you need to ensure your data is always clean and correct.