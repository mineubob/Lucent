[Home](../README.md)

# Lucent Framework Validation Guide

Lucent provides a constraint-based validation system built around the `Constraint` class and the `Validator`. Constraints are small, composable objects that each validate a single field of a data payload (typically a parsed HTTP request body). When validation fails, meaningful error messages are generated automatically.

## Table of Contents

- [Overview](#overview)
- [Basic Concepts](#basic-concepts)
- [The Validator](#the-validator)
- [Built-in Constraints](#built-in-constraints)
    - [Required](#required)
    - [Present](#present)
    - [Str](#str)
    - [Boolean](#boolean)
    - [Integer](#integer)
    - [Number](#number)
    - [Enum](#enum)
    - [Distinct](#distinct)
    - [NotIn](#notin)
    - [Length](#length)
    - [Numeric](#numeric)
    - [Range](#range)
    - [SameAs](#sameas)
    - [Matches](#matches)
- [Combinators](#combinators)
    - [All](#all)
    - [Any](#any)
    - [None](#none)
    - [One](#one)
    - [AllOrNothing](#allornothing)
    - [Optional](#optional)
    - [Shape](#shape)
    - [Each](#each)
- [Nested Validation](#nested-validation)
- [Normalizers](#normalizers)
- [Context Bag](#context-bag)
- [Custom Messages](#custom-messages)
- [Custom Constraints](#custom-constraints)
- [Accessing Results](#accessing-results)
- [Real-world Example](#real-world-example)

## Overview

The validation system is built around the abstract `Constraint` class. Each constraint validates a single field and produces an error message when the value does not satisfy its rule. Constraints are passed to a `Validator`, which applies them to a data payload and collects the results.

## Basic Concepts

- **Constraint** — a single validation rule applied to one field.
- **Validator** — applies a set of constraints to a data payload and returns a `Result`.
- **Result** — holds the validation errors and any normalized values.
- **FieldContext** — passed to each constraint; exposes the field name, value, optional originating request, and helpers.

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

The `Validator` is decoupled from HTTP — it validates plain arrays (and an
optional map of uploaded files), so the same constraints work for CLI args,
config, or API payloads:

```php
$result = $validator->validate([
    'name'     => 'Ada',
    'username' => 'ada',
    'email'    => 'ada@example.com',
]);

if ($result->hasErrors()) {
    // handle errors
}
```

When validating an HTTP request, use the convenience wrapper on
`ServerRequest::validate()`, which passes the parsed body and uploaded files
through unchanged (so object bodies and a null body are preserved):

```php
$result = $request->validate([
    'name'     => new Required(),
    'username' => new Length(min: 3, max: 40),
    'email'    => new Email(),
]);
```

Every present field's raw value is stored in the result, so validated-but-not-
normalized fields are still retrievable via `$result->value('email')`.
Constraints that normalize (e.g. `Numeric`) overwrite the raw value with the
transformed one.

## Context Bag

Per-request values (e.g. the originating `ServerRequest`, the authenticated
user, a tenant id) can be passed into validation and read by constraints via
the context bag. The bag is resolved once when the `FieldContext` is created
and is immutable for the lifetime of the validation call, so it is
coroutine-safe and never bleeds across requests.

Pass values to the `Validator` directly:

```php
$result = $validator->validate($body, $files, [
    'user_id' => $user->id,
    'tenant'  => $tenant,
]);
```

`ServerRequest::validate()` automatically seeds the request itself under the
`request` key, and accepts additional user-provided values alongside it:

```php
$result = $request->validate([
    'email' => new UniqueEmail(),
], [
    'user_id' => $user->id,
]);
```

A constraint reads values via `FieldContext::context()`, a typed getter
mirroring `Result::valueAs()` — scalar types as string literals, classes via
`::class`:

```php
public function validate(FieldContext $ctx): bool
{
    $request = $ctx->context('request', ServerRequestInterface::class);
    $userId  = $ctx->context('user_id', 'int');
    // ...
}
```

Static configuration (min lengths, regexes, table names) belongs in the
constraint constructor; the context bag is for values that vary per request.

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

### Str

Validates that the value is a string. An explicit type check that composes
with `Optional` and `All`. Unlike `Length`, which implicitly requires a
string, `Str` gives a clear, dedicated error message when the value is not a
string.

```php
use Lucent\Validation\Constraints\Str;

new Str();
```

### Boolean

Validates and normalizes a boolean value. Accepts real booleans as well as
the common string/numeric representations `'true'`, `'false'`, `'1'`, `'0'`,
`1`, and `0`. The word forms are case-insensitive (`'TRUE'`, `'True'`). On
success the value is normalized to a real `bool`.

```php
use Lucent\Validation\Constraints\Boolean;

new Boolean();
```

```php
$result = $validator->validate(['active' => 'true']);
$active = $result->value('active');  // true (a real bool)
```

### Integer

Validates that the value is an integer. An explicit type check that
complements `Numeric`, which coerces numeric strings to a number. `Integer`
rejects floats and numeric strings, requiring a genuine `int`.

```php
use Lucent\Validation\Constraints\Integer;

new Integer();
```

### Number

Validates that the value is a number (integer or float). An explicit type
check that complements `Numeric`, which coerces numeric strings to a number.
`Number` rejects numeric strings, requiring a genuine `int` or `float`.

```php
use Lucent\Validation\Constraints\Number;

new Number();
```

### Enum

Validates a value against the cases of a PHP enum. Accepts a backed enum's
backing value (string or int) or the enum instance itself, and normalizes the
value to the matching enum case on success. For a pure (non-backed) enum, the
enum instance or its case name is accepted.

```php
use Lucent\Validation\Constraints\Enum;

new Enum(ChallengeMethod::class);
```

```php
$result = $validator->validate(['method' => 'email']);
$method = $result->value('method');  // ChallengeMethod::Email
```

### Distinct

Validates that every element of an array value is unique. Useful for lists of
tags, ids, or other values that must not repeat. Comparison is strict, so
`1` and `'1'` are treated as distinct.

```php
use Lucent\Validation\Constraints\Distinct;

new Distinct();
```

### Unique

Validates that a value does not already exist in a data store. The constraint
is decoupled from any storage backend: it takes a callable that answers
whether a conflicting row exists for the value. Empty values (null, empty
string, empty array) always pass — presence is the responsibility of
`Required`.

```php
use Lucent\Validation\Constraints\Unique;

new Unique(fn (mixed $value) => User::where('email', $value)->count() > 0);
```

For model-backed uniqueness, prefer the `Model::uniqueConstraint()` factory,
which builds the callable from a model and column. Pass the current record's
primary key as the second argument to exclude it from the check when updating
an existing row:

```php
// Create: reject an email already in use.
$request->validate([
    'email' => User::uniqueConstraint('email'),
]);

// Update: allow the user to keep their own email.
$request->validate([
    'email' => User::uniqueConstraint('email', $user->id),
]);
```

### NotIn

Validates that a value is not one of a set of forbidden values. The
complement of a hypothetical `In` constraint. Comparison is strict.

```php
use Lucent\Validation\Constraints\NotIn;

new NotIn(['admin', 'root']);
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
$result = $validator->validate(['birthdate' => '2026-01-15']);
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
$result = $validator->validate(['website' => 'https://example.com']);
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

### None

Passes only when none of the wrapped constraints pass. The inverse of `Any`:
the field is valid only when every wrapped constraint fails. Useful for "must
not be any of these" rules. When a constraint matches, a generic "must not
match" message is recorded first, followed by the matched constraint's
message, so the user sees which rule the value matched (and therefore must
not match).

```php
use Lucent\Validation\Combinators\None;
use Lucent\Validation\Constraints\Matches;

None::of(Matches::alpha(), Matches::mobile());
```

### One

Passes only when exactly one of the wrapped constraints passes — the
exclusive-or of `Any` and `None`. Each alternative is validated in isolation,
so a failed branch's errors are rolled back and never leak onto the result.
If none match, the field fails with a single generic "must match exactly one"
message. If more than one matches, the field also fails, and the generic
message is reported first, followed by each matched constraint's message so
the user sees which rules the value matched (and therefore must not both
match). Useful for mutually exclusive alternatives, e.g. "either a phone
number or an email, but not both".

```php
use Lucent\Validation\Combinators\One;
use Lucent\Validation\Constraints\Matches;

One::of(Matches::email(), Matches::mobile());
```

### AllOrNothing

Requires a group of fields to be provided together. Either every field in the
group is present, or none are. When all are present, each is validated by its
own constraint; when none are present, the group passes and is normalized to
`null` (mirroring `Optional`). A partial group (some fields present, some
absent) fails.

This is useful for a set of related optional fields that must be supplied as a
unit — for example a `billing_address` group where all sub-fields are required
together.

```php
use Lucent\Validation\Combinators\AllOrNothing;
use Lucent\Validation\Constraints\Required;

AllOrNothing::of([
    'street' => new Required(),
    'city'   => new Required(),
]);
```

```php
$validator = new Validator([
    'billing' => AllOrNothing::of([
        'street' => new Required(),
        'city'   => new Required(),
    ]),
]);

$result = $validator->validate(['billing' => []]);            // passes, value is null
$result = $validator->validate(['billing' => ['street' => '1 Main St']]);  // fails (partial)
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

$result = $validator->validate([
    'user' => ['name' => 'Ada', 'email' => 'ada@example.com'],
]);

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

$result = $validator->validate(['pair' => ['42', 'abc']]);
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

$result = $validator->validate(['items' => ['1', '2', '3']]);
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

`validate()` accepts any value, so a top-level scalar constraint can validate
a plain value directly:

```php
use Lucent\Validation\Constraints\Length;

$validator = new Validator(new Length(min: 3));
$result = $validator->validate('abc');   // passes
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
- `valueAs($path, $type, $default)` — a value cast to a given type
- `hasValue($path)` — whether a value exists at a path

Every present field is seeded with its raw value, so validated-but-not-
normalized fields are retrievable. Normalizers overwrite the raw value with
the transformed one. Nested fields are addressed by dotted path.

```php
$result = $validator->validate([
    'email' => 'ada@example.com',
    'user'  => ['name' => 'Ada'],
]);

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

### Typed getter

`valueAs()` casts a value to a given type. Scalar types are passed as string
literals (`'int'`, `'string'`, `'bool'`, `'float'`, `'array'`); userland
classes use `::class`. It falls back to the default when the path is absent or
the value cannot be cast.

```php
$age  = $result->valueAs('age', 'int');          // (int) value
$name = $result->valueAs('name', 'string');      // (string) value
$user = $result->valueAs('user', User::class);   // value, or $default
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

$result = $validator->validate([
    'name'                  => 'Ada',
    'email'                 => 'ada@example.com',
    'password'              => 'secret123',
    'password_confirmation' => 'secret123',
    'address'               => ['street' => '1 Main St', 'city' => 'Sydney'],
    'scores'                => ['1', '2', '3'],
]);
```