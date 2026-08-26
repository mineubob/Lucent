[Home](../README.md)

# Lucent Framework Validation Guide

Lucent provides a constraint-based validation system built around the `Constraint` class and the `Validator`. Constraints are small, composable objects that each validate a single field of an incoming PSR-7 request. When validation fails, meaningful error messages are generated automatically.

## Table of Contents

- [Overview](#overview)
- [Basic Concepts](#basic-concepts)
- [The Validator](#the-validator)
- [Built-in Constraints](#built-in-constraints)
    - [Required](#required)
    - [Present](#present)
    - [Length](#length)
    - [Numeric](#numeric)
    - [Range](#range)
    - [SameAs](#sameas)
    - [Matches](#matches)
- [Combinators](#combinators)
    - [All](#all)
    - [Any](#any)
    - [Optional](#optional)
    - [Shape](#shape)
    - [Each](#each)
- [Nested Validation](#nested-validation)
- [Normalizers](#normalizers)
- [Custom Messages](#custom-messages)
- [Custom Constraints](#custom-constraints)
- [Accessing Results](#accessing-results)
- [Real-world Example](#real-world-example)

## Overview

The validation system is built around the abstract `Constraint` class. Each constraint validates a single field and produces an error message when the value does not satisfy its rule. Constraints are passed to a `Validator`, which applies them to a PSR-7 `ServerRequestInterface` and collects the results.

## Basic Concepts

- **Constraint** — a single validation rule applied to one field.
- **Validator** — applies a set of constraints to a request and returns a `Result`.
- **Result** — holds the validation errors and any normalized values.
- **FieldContext** — passed to each constraint; exposes the field name, value, request, and helpers.

## The Validator

The `Validator` takes either a single top-level constraint or an associative
array of constraints keyed by field name. Each value must be a `Constraint`
instance. A flat array is sugar for a top-level `Shape` (see below).

```php
use Lucent\Validation\Validator;
use Lucent\Validation\Constraints\Required;
use Lucent\Validation\Constraints\Length;
use Lucent\Validation\Constraints\Email;

$validator = new Validator([
    'name'     => new Required(),
    'username' => new Length(min: 3, max: 40),
    'email'    => new Email(),
]);
```

Validate a PSR-7 request:

```php
$result = $validator->validate($request);

if ($result->hasErrors()) {
    // handle errors
}
```

Every present field's raw value is stored in the result, so validated-but-not-
normalized fields are still retrievable via `$result->value('email')`.
Constraints that normalize (e.g. `Numeric`) overwrite the raw value with the
transformed one.

## Built-in Constraints

### Required

Ensures the field is present. Treats `null`, an empty string, and an empty
array as missing. Values like `'0'`, `0`, `false`, and whitespace-only strings
are considered present.

```php
use Lucent\Validation\Constraints\Required;

new Required();
```

### Present

Checks only that the field key was present in the request body. Unlike
`Required`, the value may be `null`, an empty string, or `false` and still
pass. This is useful for booleans and checkboxes where an explicit `false` or
`0` is a valid submission but the key must still be supplied.

```php
use Lucent\Validation\Constraints\Present;

new Present();
```

### Length

Validates the length of a string (characters) or array (items). Requires at least one of `$min` or `$max`.

```php
use Lucent\Validation\Constraints\Length;

new Length(min: 3);          // at least 3 characters
new Length(max: 100);        // at most 100 characters
new Length(min: 3, max: 100); // between 3 and 100
```

### Numeric

Ensures the value is numeric. Numeric strings are normalized to a number.

```php
use Lucent\Validation\Constraints\Numeric;

new Numeric();
```

### Range

Validates a numeric value against a minimum and/or maximum. Requires at least one of `$min` or `$max`. Accepts `int`, `float`, and numeric strings (which are normalized to numbers).

```php
use Lucent\Validation\Constraints\Range;

new Range(min: 18);          // at least 18
new Range(max: 120);         // at most 120
new Range(min: 18, max: 120); // between 18 and 120
```

### SameAs

Ensures the field matches the value of another field.

```php
use Lucent\Validation\Constraints\SameAs;

new SameAs('password'); // e.g. for a password_confirmation field
```

### Matches

Validates a string against a regular expression. Use the constructor for a custom pattern, or one of the built-in factories for common formats.

```php
use Lucent\Validation\Constraints\Matches;

new Matches('/^[a-z0-9]+$/');   // custom pattern
Matches::mobile();              // E.164 international phone
Matches::password();            // lowercase + uppercase + min 8
Matches::alpha();               // letters only
Matches::alphanumeric();        // letters and numbers
Matches::hexColor();            // #FFF or #FFFFFF
```

> **ReDoS warning:** the pattern is applied to attacker-controlled input via
> `preg_match`. A pattern with catastrophic backtracking (e.g. `/(a+)+$/`)
> applied to a long adversarial string can consume significant CPU. Patterns
> must be **developer-controlled and pre-validated** — never derived from user
> input. For patterns that may come from an untrusted source, use
> `Matches::safePattern($pattern)`, which rejects patterns containing nested
> quantifiers.

### Email

Validates an email address using PHP's `FILTER_VALIDATE_EMAIL`.

```php
use Lucent\Validation\Constraints\Email;

new Email();
```

### Ip

Validates an IP address using PHP's `FILTER_VALIDATE_IP`. Accepts IPv4 and
IPv6 by default; pass `Ip::IPV4` or `Ip::IPV6` to restrict.

```php
use Lucent\Validation\Constraints\Ip;

new Ip();            // IPv4 or IPv6
new Ip(Ip::IPV4);    // IPv4 only
new Ip(Ip::IPV6);    // IPv6 only
```

### Date

Validates a date string against a format (default `Y-m-d`) using Carbon.
Rejects rollovers (e.g. `2023-13-31`) and non-strict input (e.g. `2023-1-1`).
On success the value is normalized to a `Carbon` instance.

```php
use Lucent\Validation\Constraints\Date;

new Date();                 // YYYY-MM-DD
new Date('d/m/Y');          // DD/MM/YYYY
```

```php
$result = $validator->validate($request);
$date = $result->value('birthdate');  // a Carbon instance
$date->format('d/m/Y');
```

### Uri

Validates a string as a well-formed URI using `Lucent\Http\Message\Uri::isValid()`.
On success the value is normalized to a `Uri` instance, so downstream code can
access the parsed components (scheme, host, path, etc.) from the result.

```php
use Lucent\Validation\Constraints\Uri;

new Uri();  // default: requires a scheme and host (VALIDATE_DEFAULT)
```

Pass `Uri::VALIDATE_*` flags to change the requirements:

```php
use Lucent\Validation\Constraints\Uri;
use Lucent\Http\Message\Uri as MessageUri;

new Uri(MessageUri::VALIDATE_RELATIVE);   // accept relative references
new Uri(MessageUri::VALIDATE_STRICT);     // http/https only
```

```php
$result = $validator->validate($request);
$uri = $result->value('website');  // a Uri instance
$host = $uri->getHost();
```

### Uuid

Validates a string as a UUID using `Lucent\Facades\UUID::isValid()`. Accepts
versions 1–7 by default, and supports version-specific validation.

```php
use Lucent\Validation\Constraints\Uuid;

new Uuid();        // any version (1-7)
new Uuid(4);       // version 4 only
new Uuid(7);       // version 7 only
```

### UploadedFile

Validates that the field contains a successfully uploaded file. Reads the
uploaded files from the request and passes when the file's error code is
`UPLOAD_ERR_OK`.

```php
use Lucent\Validation\Constraints\UploadedFile;

new UploadedFile();
```

## Combinators

Combinators wrap other constraints to express more complex logic.

### All

Requires every wrapped constraint to pass. Fails on the first failing constraint.

```php
use Lucent\Validation\Combinators\All;
use Lucent\Validation\Constraints\Length;
use Lucent\Validation\Constraints\Matches;

All::of(new Length(min: 8), Matches::password());
```

### Any

Passes if any wrapped constraint passes.

```php
use Lucent\Validation\Combinators\Any;
use Lucent\Validation\Constraints\Matches;

Any::of(Matches::alpha(), Matches::mobile());
```

### Optional

Skips validation when the field was not present in the request body, or when
its value is null, an empty string, or an empty array. A present-but-empty
value is normalized to null; an absent field is left untouched so it does not
appear in the result.

```php
use Lucent\Validation\Combinators\Optional;
use Lucent\Validation\Constraints\Length;

new Optional(new Length(min: 3, max: 100));
```

### Shape

Validates an array value as an **object** or a **tuple**, selected by factory.

#### `Shape::object(...)` — named sub-fields

Validates a map of named sub-fields, each with its own constraint. Sub-field
errors and values are namespaced under the parent field's dotted path
(`user.name`). Accepts arrays and objects (via `get_object_vars`).

```php
use Lucent\Validation\Combinators\Shape;
use Lucent\Validation\Constraints\Required;
use Lucent\Validation\Constraints\Email;

Shape::object([
    'name'  => new Required(),
    'email' => new Email(),
]);
```

Nested inside a validator:

```php
$validator = new Validator([
    'user' => Shape::object([
        'name'  => new Required(),
        'email' => new Email(),
    ]),
]);

$result = $validator->validate($request);

$result->value('user.name');   // 'Ada'
$result->value('user');        // ['name' => 'Ada', 'email' => 'ada@example.com']
```

#### `Shape::tuple(...)` — fixed-length positional list

Validates a fixed-length, positional list where each position has its own
constraint. The tuple has exactly as many positions as constraints. Element
errors and values are namespaced by index (`pair.0`).

```php
use Lucent\Validation\Combinators\Shape;
use Lucent\Validation\Constraints\Numeric;
use Lucent\Validation\Constraints\Length;

Shape::tuple(new Numeric(), new Length(min: 2));   // [number, string]
```

```php
$validator = new Validator([
    'pair' => Shape::tuple(new Numeric(), new Length(min: 2)),
]);

$result = $validator->validate($request);
$result->value('pair.0');   // 42 (normalized)
```

A tuple rejects values with the wrong number of elements, and each position is
validated by its own constraint, so the types may differ freely.

When a sub-field or position fails, `Shape` returns `false` (so it composes
correctly with `All`/`Any`/`Optional`) but reports no generic error of its own
— the specific child error is already recorded at its dotted path.

### Each

Validates every element of an array value against a single constraint. Element
errors and values are namespaced under the parent field's dotted path with the
element index (`items.0`). Works on both lists and associative arrays.

```php
use Lucent\Validation\Combinators\Each;
use Lucent\Validation\Constraints\Numeric;

new Each(new Numeric());   // array of numbers
new Each(new Numeric(), maxItems: 100);  // bound the array size
```

```php
$validator = new Validator([
    'items' => new Each(new Numeric()),
]);

$result = $validator->validate($request);
$result->value('items');   // [1, 2, 3] (normalized)
```

## Nested Validation

`Shape` and `Each` compose to validate arbitrarily nested structures.

```php
use Lucent\Validation\Combinators\Each;
use Lucent\Validation\Combinators\Shape;
use Lucent\Validation\Constraints\Required;

$validator = new Validator([
    'users' => new Each(Shape::object([
        'name' => new Required(),
    ])),
]);
```

Errors are namespaced by path, so `users.1.name` pinpoints the failing field.

The `Validator` also accepts a single top-level constraint, so an array body
can be validated directly:

```php
use Lucent\Validation\Combinators\Each;
use Lucent\Validation\Constraints\Numeric;

$validator = new Validator(new Each(new Numeric()));  // body is an array of numbers
```

## Normalizers

Normalizers transform a value during validation. They always pass and write the normalized value back into the result.

### Trim

Trims whitespace from string values.

```php
use Lucent\Validation\Normalizers\Trim;

new Trim();
```

## Custom Messages

Override the default message for any constraint with `withMessage()`. The message can be a string or a closure receiving the `FieldContext`.

```php
use Lucent\Validation\Constraints\Matches;

Matches::email()->withMessage('Please provide a valid email address.');
```

```php
use Lucent\Validation\Constraints\Length;

(new Length(min: 8))->withMessage(fn($ctx) => "The {$ctx->field} must be at least 8 characters.");
```

## Custom Constraints

Extend the abstract `Constraint` class and implement `validate()` and `defaultMessage()`.

```php
use Closure;
use Lucent\Validation\Constraint;
use Lucent\Validation\FieldContext;

class ZipCode extends Constraint
{
    protected function defaultMessage(): string|Closure|null
    {
        return fn($ctx) => "The {$ctx->field} must be a valid ZIP code.";
    }

    public function validate(FieldContext $ctx): bool
    {
        return is_string($ctx->value) && preg_match('/^\d{5}(-\d{4})?$/', $ctx->value) === 1;
    }
}
```

`defaultMessage()` may return a string, a closure producing a string, or
`null`. Returning `null` signals that no message should be reported — used by
combinators whose child constraints already recorded their specific errors on
the result. When a constraint returns `false` from `validate()` but `null`
from `message()`, the parent skips adding a redundant generic error.

## Accessing Results

The `Result` returned by `Validator::validate()` exposes:

- `errors()` — associative array of dotted field path => array of messages
- `hasErrors()` — whether any errors occurred
- `values()` — validated values as a nested array
- `value($path, $default)` — a single value by dotted path
- `hasValue($path)` — whether a value exists at a path

Every present field is seeded with its raw value, so validated-but-not-
normalized fields are retrievable. Normalizers overwrite the raw value with
the transformed one. Nested fields are addressed by dotted path.

```php
$result = $validator->validate($request);

if ($result->hasErrors()) {
    foreach ($result->errors() as $field => $messages) {
        foreach ($messages as $message) {
            // ...
        }
    }
}

$email = $result->value('email');        // 'ada@example.com'
$name  = $result->value('user.name');    // 'Ada'
$user  = $result->value('user');         // ['name' => 'Ada', 'email' => 'ada@example.com']
```

## Real-world Example

```php
use Lucent\Validation\Validator;
use Lucent\Validation\Combinators\All;
use Lucent\Validation\Combinators\Each;
use Lucent\Validation\Combinators\Optional;
use Lucent\Validation\Combinators\Shape;
use Lucent\Validation\Constraints\Email;
use Lucent\Validation\Constraints\Length;
use Lucent\Validation\Constraints\Matches;
use Lucent\Validation\Constraints\Numeric;
use Lucent\Validation\Constraints\Required;
use Lucent\Validation\Constraints\SameAs;
use Lucent\Validation\Normalizers\Trim;

$validator = new Validator([
    'name'                  => All::of(new Required(), new Length(max: 100), new Trim()),
    'email'                 => new Email(),
    'password'              => All::of(new Length(min: 8), Matches::password()),
    'password_confirmation' => new SameAs('password'),
    'phone'                 => new Optional(Matches::mobile()),
    'address'               => Shape::object([
        'street' => new Required(),
        'city'   => new Required(),
    ]),
    'scores'                => new Each(new Numeric()),
]);

$result = $validator->validate($request);
```