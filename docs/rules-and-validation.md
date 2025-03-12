[Home](../README.md)

# Lucent Framework Validation Guide

The Lucent Framework provides a powerful and flexible validation system through its `Rule` classes. This guide will help you understand how to implement validation in your application to ensure data integrity and security.

## Table of Contents

- [Overview](#overview)
- [Basic Concepts](#basic-concepts)
- [Creating Validation Rules](#creating-validation-rules)
- [Using Validation Rules](#using-validation-rules)
- [Available Validation Rules](#available-validation-rules)
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

### Dynamic Rules

For more flexibility, you can create rules that change based on context:

```php
<?php

namespace App\Validation;

use Lucent\Validation\Rule;

class DynamicUserRule extends Rule
{
    private array $fields;
    
    public function __construct(array $fields = [])
    {
        $this->fields = $fields;
    }
    
    public function setup(): array
    {
        $baseRules = [
            'name' => [
                'min:2',
                'max:50'
            ],
            'email' => [
                'regex:email'
            ]
        ];
        
        // Only include rules for fields that are actually present
        return array_filter($baseRules, function ($field) {
            return array_key_exists($field, $this->fields);
        }, ARRAY_FILTER_USE_KEY);
    }
}
```

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
| `regex:email` | Validates email format | `'regex:email'` |
| `regex:password` | Validates password complexity (requires one lowercase letter, one uppercase letter, min 8 chars) | `'regex:password'` |
| `same:{field}` | Ensures the value matches another field's value | `'same:password'` |
| `unique:{table}` | Ensures the value doesn't exist in the specified table column (uses the input field name as the column name) | `'unique:users'` |
| `!unique:{table}` | Ensures the value does exist in the specified table column (uses the input field name as the column name) | `'!unique:users'` |

### Validation Rule Examples

```php
// Email validation
'email' => ['regex:email']

// Password with confirmation
'password' => ['min:8', 'regex:password'],
'password_confirm' => ['same:password']

// Numeric range validation
'age' => ['min_num:18', 'max_num:120']

// Uniqueness validation - checks the 'username' column in the 'users' table
'username' => ['unique:users']

// Existence validation - checks the 'role_id' column in the 'roles' table
'role_id' => ['!unique:roles']
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
        return [
            'title' => [
                'min:5',
                'max:200'
            ],
            'slug' => [
                'min:3',
                'max:100',
                'unique:articles'  // Ensures the slug is unique in the articles table
            ],
            'content' => [
                'min:100',  // Require at least 100 characters of content
            ],
            'category_id' => [
                '!unique:categories'  // Ensure the category exists in the categories table
            ],
            'tags' => [
                'min:2',  // At least 2 characters for tags
                'max:255'  // Maximum length for all tags
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

1. Using `unique` validation to ensure article slugs don't conflict
2. Using `!unique` validation to verify a category exists
3. Implementing a custom validation method (`validate_status`)
4. Combining URL variables with validated form input
5. Returning different responses based on validation outcome

The validation ensures:
- Articles have meaningful titles (5-200 characters)
- Slugs are unique and properly formatted (3-100 characters)
- Content is substantial (at least 100 characters)
- The selected category exists in the database
- The article status is one of the allowed values

This pattern can be adapted for validating any kind of content creation or management functionality in your application.

## Best Practices

1. **Separate Concerns**: Create different rule classes for different aspects of your application, rather than a single large validation class.

2. **Descriptive Names**: Give your validation rules clear, descriptive names that indicate their purpose (e.g., `UserRegistrationRule`, `ProductCreationRule`).

3. **Reuse Rules**: Avoid duplicating validation logic by reusing rule classes or extracting common validation patterns.

4. **Document Your Rules**: Create clear documentation explaining your validation requirements, especially for complex custom rules.

5. **Security First**: Always validate data on the server-side, even if you have client-side validation.

6. **Custom Error Messages**: For complex validation requirements, consider implementing custom error messages that are more descriptive.

7. **Dynamic Rules**: Use rule constructors to create rules that can adapt to different contexts.

---

With this guide, you should be well-equipped to implement robust validation in your Lucent Framework application. Validation is a crucial part of building secure, reliable web applications, and Lucent's validation system gives you the tools you need to ensure your data is always clean and correct.