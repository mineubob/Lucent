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
- [Validation Messages](#validation-messages)
    - [Default Messages](#default-messages)
    - [Message Parameters](#message-parameters)
    - [Overriding Messages](#overriding-messages)
    - [Local Message Overrides](#local-message-overrides)
    - [Global Message Overrides](#global-message-overrides)
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
| `same:{field}` | Ensures the value matches another field's value | `'same:@password'` |
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

## Validation Messages

### Default Messages

The framework provides default error messages for built-in validation rules. These messages use placeholders that are automatically replaced with actual values when validation fails.

### Message Parameters

Validation messages support placeholders that are replaced with actual field names and rule parameters. The available placeholders depend on the validation rule:

| Rule | Available Placeholders | Example Message |
|------|------------------------|-----------------|
| `min` | `:attribute`, `:min` | "username must be at least 5 characters" |
| `max` | `:attribute`, `:max` | "username may not be greater than 20 characters" |
| `min_num` | `:attribute`, `:min` | "age must be greater than 18" |
| `max_num` | `:attribute`, `:max` | "age may not be less than 120" |
| `same` | `:attribute`, `:first` | "password_confirmation and password must match" |
| `regex` | `:attribute` | "email does not match the required format" |

The `:attribute` placeholder is automatically replaced with the field name being validated, and rule-specific placeholders (like `:min`, `:max`, etc.) are replaced with the corresponding parameter values.

For the `same` rule, when referencing another field with `@`, the field name (not its value) will be used in the error message for security reasons.

### Overriding Messages

Lucent allows you to override the default validation messages at both the local (rule class) level and the global (application) level.

### Local Message Overrides

To override messages within a specific rule class, use the `overrideRuleMessage` method:

```php
<?php

namespace App\Validation;

use Lucent\Validation\Rule;

class UserRule extends Rule
{
    public function setup(): array
    {
        // Override the min rule message for this rule class only
        $this->overrideRuleMessage("min", "The :attribute field needs at least :min characters");
        
        // Override the regex message for email validation
        $this->overrideRuleMessage("regex", "Please provide a valid :attribute address");
        
        return [
            'username' => [
                'min:5',
                'max:20'
            ],
            'email' => [
                'regex:email'
            ]
        ];
    }
}
```

### Global Message Overrides

For application-wide message overrides, use the `Rule` facade:

```php
<?php

use Lucent\Facades\Rule;

// In a service provider or bootstrap file
Rule::overrideMessage("min", "The :attribute field must have at least :min characters");
Rule::overrideMessage("max", "The :attribute field cannot exceed :max characters");
Rule::overrideMessage("same", "The :attribute field must match :first");
```

Global message overrides apply to all validation rules across your application unless overridden at the local level.

**Priority Order:**
1. Local message overrides (highest priority)
2. Global message overrides
3. Default framework messages (lowest priority)

## Advanced Usage

### Custom Validation Methods

For more complex validation needs, you can create custom validation methods. This allows you to implement application-specific validation logic beyond the built-in rules.

#### Creating Custom Validation Methods

To create a custom validation method:

1. Extend the `Rule` class
2. Add a protected method with your validation logic
3. The method should accept the value to validate as its parameter
4. Return a boolean: `true` if validation passes, `false` if it fails
5. Use the method name directly in your validation rules

Here's how to implement a ZIP code validator:

```php
<?php

namespace App\Validation;

use Lucent\Validation\Rule;

class AddressRule extends Rule
{
    public function setup(): array
    {
        // You can override the message for your custom validation rule
        $this->overrideRuleMessage("zip_code", "The :attribute must be a valid ZIP code");
        
        return [
            'address_line1' => ['min:5', 'max:100'],
            'city' => ['min:2', 'max:50'],
            'zip_code' => ['zip_code']
        ];
    }
    
    /**
     * Validates a US ZIP code
     * 
     * @param mixed $value The value to validate
     * @return bool Whether the validation passes
     */
    protected function zip_code($value): bool
    {
        // US zip code validation logic (5 digits, optionally followed by hyphen and 4 more digits)
        return preg_match('/^\d{5}(-\d{4})?$/', $value);
    }
}
```

#### Custom Validation with Parameters

You can also create custom validation methods that accept parameters:

```php
<?php

namespace App\Validation;

use Lucent\Validation\Rule;

class ProductRule extends Rule
{
    public function setup(): array
    {
        // Custom message with parameter replacement
        $this->overrideRuleMessage(
            "in_range", 
            "The :attribute must be between :min and :max"
        );
        
        return [
            'name' => ['min:3', 'max:100'],
            'price' => ['in_range:10:1000'], // Must be between $10 and $1000
            'stock' => ['in_range:1:500']    // Must be between 1 and 500 units
        ];
    }
    
    /**
     * Validates that a value is within a specified numeric range
     * 
     * @param int $min The minimum allowed value
     * @param int $max The maximum allowed value
     * @param mixed $value The value to validate
     * @return bool Whether the validation passes
     */
    protected function in_range(int $min, int $max, $value): bool
    {
        if (!is_numeric($value)) {
            return false;
        }
        
        $numericValue = floatval($value);
        return ($numericValue >= $min && $numericValue <= $max);
    }
}
```

#### Using Custom Validation with Related Data

You can create validation methods that check related fields or more complex conditions:

```php
<?php

namespace App\Validation;

use Lucent\Validation\Rule;

class ShippingRule extends Rule
{
    public function setup(): array
    {
        $this->overrideRuleMessage(
            "shipping_available", 
            "We don't ship to :attribute for orders under $100"
        );
        
        return [
            'country' => ['min:2', 'max:2'],  // Country code
            'total' => ['min_num:0'],         // Order total
            'shipping_address' => ['shipping_available:@country:@total']
        ];
    }
    
    /**
     * Validates shipping availability based on country and order total
     * 
     * @param string $country The country code
     * @param float $total The order total
     * @param string $address The shipping address
     * @return bool Whether shipping is available
     */
    protected function shipping_available(string $country, float $total, string $address): bool
    {
        // List of countries that require a minimum order total for shipping
        $restrictedCountries = ['AU', 'NZ', 'JP'];
        
        // For restricted countries, require a minimum order total of $100
        if (in_array($country, $restrictedCountries) && $total < 100) {
            return false;
        }
        
        return true;
    }
}
```

#### Using Custom Validation in Inline Rules

You can also use your custom validation methods in inline rules, not just in dedicated rule classes:

```php
<?php

namespace App\Controllers;

use Lucent\Http\Request;
use Lucent\Http\JsonResponse;
use Lucent\Validation\Rule;

class OrderController extends Rule
{
    // Custom validation method in the controller
    protected function payment_method($value): bool
    {
        $validMethods = ['credit_card', 'paypal', 'bank_transfer'];
        return in_array($value, $validMethods);
    }
    
    public function createOrder(Request $request): JsonResponse
    {
        // Using the custom validation method in inline rules
        if (!$request->validate([
            'items' => ['min:1'],
            'payment_method' => ['payment_method']
        ])) {
            return new JsonResponse()
                ->setOutcome(false)
                ->setStatusCode(400)
                ->setMessage("Invalid order data")
                ->addErrors($request->getValidationErrors());
        }
        
        // Process the order...
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
        // Override messages for more user-friendly errors
        $this->overrideRuleMessage("min", "Your :attribute must be at least :min characters");
        $this->overrideRuleMessage("regex", "Please enter a valid :attribute");
        
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
        
        // Override validation messages
        $this->overrideRuleMessage("min", "The :attribute needs to be at least :min characters");
        $this->overrideRuleMessage("unique", "This :attribute is already taken, please choose another");
        
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
6. Customizing error messages with parameter replacement
7. Combining URL variables with validated form input
8. Returning different responses based on validation outcome

## Best Practices

1. **Separate Concerns**: Create different rule classes for different aspects of your application, rather than a single large validation class.

2. **Descriptive Names**: Give your validation rules clear, descriptive names that indicate their purpose (e.g., `UserRegistrationRule`, `ProductCreationRule`).

3. **Reuse Rules**: Avoid duplicating validation logic by creating shared rule classes that can be extended.

4. **Document Your Rules**: Create clear documentation explaining your validation requirements, especially for complex custom rules.

5. **Security First**: Always validate data on the server-side, even if you have client-side validation.

6. **Customize Error Messages**: Use message overrides to provide clear, user-friendly guidance when validation fails. Keep them specific to your application's context and audience.

7. **Use Nullable Properly**: Use the `nullable` rule for optional fields rather than adding complex conditional logic.

8. **Field References**: When referencing other fields (like in the `same` rule), use the `@` prefix (e.g., `same:@password`).

9. **Centralize Regex Patterns**: Store frequently used regex patterns in a central location using the Regex facade.

10. **Validation Strategy**: Use negated rules (`!`) when checking for the absence of a condition is more logical than checking for its presence.

11. **Message Consistency**: Maintain a consistent tone and style in your validation messages across the application.

12. **Global vs. Local Overrides**: Use global message overrides for application-wide consistency, and local overrides when specific contexts need different wording.

---

With this guide, you should be well-equipped to implement robust validation in your Lucent Framework application. Validation is a crucial part of building secure, reliable web applications, and Lucent's validation system gives you the tools you need to ensure your data is always clean and correct.