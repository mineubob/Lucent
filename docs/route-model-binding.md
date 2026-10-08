[Home](../README.md)

# Route Model Binding

Route model binding resolves a controller parameter type-hinted to a Radiant model from a route variable. Binding is **opt-in** via the `#[Bind]` attribute — a bare Model type-hint is never auto-resolved from the URL.

> **Why opt-in?** An implicit primary-key lookup with no ownership/tenant check is an IDOR risk: any user could fetch any row by id. `#[Bind]` makes the lookup explicit and gives you the hooks to scope it.

## Basic Usage

The route variable is always the **parameter name**:

```php
use App\Models\User;
use Lucent\Support\Attributes\Bind;

// Route: /users/{user}
public function show(#[Bind] User $user): Response
{
    // $user is the User whose primary key matches {user}
}
```

`#[Bind]` with no arguments binds by the model's **primary key**.

## Binding by a Non-PK Column

Use `resolve:` to bind on any declared column — a slug, a UUID, etc.:

```php
// Route: /posts/{post}
public function show(#[Bind(resolve: 'slug')] Post $post): Response
{
    // Resolves WHERE slug = {post}
}
```

## Scoping (the IDOR fix)

Use `scope:` to constrain the lookup — the classic ownership/tenant check. Because PHP attributes only accept **constant expressions**, the scope is an **invokable class-string** or a `[Class::class, 'method']` array of constants:

```php
<?php

namespace App\Security;

class TenantScope
{
    public function __invoke($query, mixed $value, array $vars, ServerRequestInterface $request)
    {
        return $query->where('tenant_id', '=', $request->getAttribute('tenant_id'));
    }
}
```

```php
// Route: /posts/{post}
public function update(
    #[Bind(scope: \App\Security\TenantScope::class)]
    Post $post,
): Response {
    // Only resolves when the post belongs to the current tenant;
    // otherwise 404.
}

// Method form — [Class::class, 'method'] arrays of constants are also
// legal attribute arguments:
public function edit(
    #[Bind(scope: [\App\Security\TenantScope::class, 'apply'])]
    Post $post,
): Response { ... }
```

The scope receives `($query, $value, $vars, $request)` — the query builder, the route variable's value, all route variables, and the **current PSR-7 request**. Return the (possibly modified) query.

## Connection Splitting

Use `connection:` to resolve the model on a named connection — the lookup runs there and the previous active connection is restored afterwards (no bleed):

```php
// Static connection name
public function profile(#[Bind(connection: 'tenant')] User $user): Response { ... }

// Dynamic — an invokable class-string returning the connection name
public function audit(
    #[Bind(connection: \App\Security\TenantConnection::class)]
    User $user,
): Response { ... }
```

## Composite Primary Keys

A single route variable carries one value, so a composite PK **cannot** be resolved implicitly — the framework never guesses where the other key parts come from. A composite PK requires an explicit `resolve` callable returning the full `[column => value]` map:

```php
<?php

namespace App\Security;

class ReportKey
{
    public function __invoke(array $vars, ServerRequestInterface $request): array
    {
        return [
            'id'        => $vars['report'],
            'tenant_id' => $request->getAttribute('tenant_id'),
        ];
    }
}
```

```php
// Route: /workspaces/{workspace}/reports/{report}
public function show(
    #[Bind(resolve: \App\Security\ReportKey::class)]
    Report $report,
): Response { ... }
```

A composite PK **without** an explicit `resolve` throws at binding time — fail loud, never guess.

## Callable Signatures

PHP cannot enforce callable shapes inside attributes, so these signatures are enforced by convention:

| Argument | Signature |
|---|---|
| `resolve` | `callable(array $vars, ServerRequestInterface $request): array<string, mixed>` |
| `scope` | `callable(ModelQueryBuilder $query, mixed $value, array $vars, ServerRequestInterface $request): ModelQueryBuilder` |
| `connection` | `callable(array $vars, ServerRequestInterface $request): string` |

Supported callable forms in attribute arguments: invokable class-strings (`SomeScope::class`) and `[Class::class, 'method']` arrays. Closures are a compile error inside attributes but work when a `Bind` is constructed programmatically.

## Failure Behaviour

| Situation | Result |
|---|---|
| No `#[Bind]` on a Model parameter | No binding — the container resolves the parameter (or the controller fetches it) |
| Row not found | `ModelBindingException` (a 404 `HttpException` carrying the model class and attempted keys) |
| Scope filters everything out | `ModelBindingException` (404) |
| Route variable missing for the parameter name | `InvalidArgumentException` (route misconfiguration) |
| Composite PK without `resolve` | `InvalidArgumentException` (fail loud) |
| Unknown column in `resolve` | Rejected by the query builder's column validation |

## Complete Example

```php
<?php

namespace App\Controllers;

use App\Models\Post;
use App\Security\TenantScope;
use Lucent\Http\Message\Response;
use Lucent\Support\Attributes\Bind;

class PostController
{
    // Bind by PK
    public function show(#[Bind] Post $post): Response
    {
        return Response::json(['post' => $post], 200);
    }

    // Bind by slug
    public function bySlug(#[Bind(resolve: 'slug')] Post $post): Response
    {
        return Response::json(['post' => $post], 200);
    }

    // Bind scoped to the tenant
    public function update(
        #[Bind(scope: TenantScope::class)]
        Post $post,
    ): Response {
        $post->title = 'Updated';
        $post->save();

        return Response::json(['post' => $post], 200);
    }
}
```

```php
use Lucent\Facades\Route;

Route::rest()->group('posts')
    ->prefix('/posts')
    ->defaultController(PostController::class)
    ->get(path: '/{post}', method: 'show')
    ->get(path: '/slug/{post}', method: 'bySlug')
    ->put(path: '/{post}', method: 'update');
```
