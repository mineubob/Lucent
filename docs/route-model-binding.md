[Home](../README.md)

# Route Model Binding in Lucent

## Introduction

Route Model Binding is a powerful feature in the Lucent framework that automatically resolves route parameters into corresponding model instances. This simplifies controller logic by handling the database lookup for you, making your code more readable and concise.

## How Route Model Binding Works

Instead of manually querying the database to find a model based on an ID passed in the URL, Route Model Binding does this automatically. When you type-hint a parameter in your controller method with a model class, Lucent will:

1. Extract the URL parameter value
2. Use the model's primary key to look up the instance
3. Inject the found model into your controller method
4. Return a 404 response if the model cannot be found

## How It Works Behind the Scenes

When route model binding is active, the framework does the following:

1. Extracts the value from the URL parameter
2. Uses reflection to determine which model class to instantiate
3. Attempts to find the model by its primary key
4. If found, injects the model into your controller method
5. If not found, automatically returns a 404 response

This all happens transparently, so you can focus on your application logic rather than boilerplate code.


## Real-World Example

Let's walk through a simple but practical example of route model binding with a blog application.

### 1. Article Model

```php
<?php

namespace App\Models;

use Lucent\Model\Column;
use Lucent\Model\ColumnType;
use Lucent\Model;

class Article extends Model
{
    #[Column(ColumnType::INT, name: "id", primaryKey: true, autoIncrement: true)]
    public int $id;

    #[Column(ColumnType::VARCHAR, name: "title", length: 200)]
    public string $title;

    #[Column(ColumnType::TEXT, name: "content")]
    public string $content;

    #[Column(ColumnType::BOOLEAN, name: "published", default: 0)]
    public bool $published = false;
}
```

### 2. Simple Logger Middleware

```php
<?php

namespace App\Middleware;

use Lucent\Facades\Log;
use Lucent\Http\Message\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class LoggerMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Get the URL parameter if it exists
        $articleId = $request->getUrlVar('article');
        
        if ($articleId) {
            Log::channel('requests')->info("Accessing article: " . $articleId);
        }
        
        return $handler->handle($request);
    }
}
```

### 3. Article Controller

```php
<?php

namespace App\Controllers;

use App\Models\Article;
use Lucent\Http\Message\Response;

class ArticleController
{
    /**
     * Get article details using route model binding
     * 
     * @param Article $article Auto-resolved from route parameter
     */
    public function show(Article $article): Response
    {
        // Article is already loaded through route model binding
        return Response::json([
            'article' => [
                'id' => $article->id,
                'title' => $article->title,
                'content' => $article->content,
                'published' => $article->published
            ]
        ], 200);
    }
    
    /**
     * Get all published articles (no model binding)
     */
    public function index(): Response
    {
        $articles = Article::where('published', true)->get();
        
        return Response::json(['articles' => $articles], 200);
    }
}
```

### 4. Route Definitions

```php
<?php

use App\Controllers\ArticleController;
use App\Middleware\LoggerMiddleware;
use Lucent\Facades\Route;

Route::rest()->group('articles')
    ->prefix('/articles')
    ->defaultController(ArticleController::class)
    ->middleware([LoggerMiddleware::class])
    ->get(path: '/', method: 'index')
    ->get(path: '/{article}', method: 'show');
```

### How It All Works Together

1. **Route Definition**: We define a route `/articles/{article}` that accepts an article ID

2. **Middleware Processing**: When a request comes in, the `LoggerMiddleware` runs first:
    - It logs the article ID being accessed
    - Passes the request on to the next stage

3. **Route Model Binding**: Before the controller method is called:
    - The framework extracts the `{article}` parameter value
    - It looks up the Article model with that ID
    - If found, it creates an Article instance
    - If not found, it returns a 404 response

4. **Controller Execution**: If the article exists, the controller's `show` method runs:
    - It receives the resolved `Article` model as its parameter
    - It doesn't need to perform any database lookups
    - It can immediately use the article's properties

This demonstrates the elegance of route model binding: the controller receives fully loaded models rather than IDs, eliminating repetitive database lookup code.

## Benefits of Route Model Binding

1. **Simplified Controller Methods**: Eliminate boilerplate database lookup code
2. **Automatic 404 Handling**: Framework handles missing records automatically
3. **Type-Safe Parameters**: IDE autocompletion and type checking for model instances
4. **Consistent Error Handling**: Standardized 404 responses for missing resources
5. **Context Awareness**: Models can be loaded from request context first if available

## Context-Aware Binding

Route Model Binding is context-aware. If a model instance already exists in the request context with the same ID, it will use that cached instance instead of making another database query.

This optimization prevents duplicate database queries when the same model is needed in multiple places during a request lifecycle.

## How This Example Demonstrates Route Model Binding

This simpler example clearly shows the power of route model binding:

1. **Zero Database Queries in the Controller**: The controller doesn't contain any code to fetch the article - it's automatically provided.

2. **Automatic 404 Handling**: If an article with the requested ID doesn't exist, the framework automatically returns a 404 response without the controller ever being called.

3. **Clean Middleware Integration**: The middleware can access the route parameter before it's converted to a model, allowing for logging or other pre-processing.

4. **Focused Controller Logic**: The controller method can focus on its core responsibility (returning article data) rather than fetching data.

5. **Type Safety**: By type-hinting with `Article`, we get IDE autocompletion and type checking for the model instance.

## Best Practices

1. **Name Consistency**: Match route parameter names with model class names (in lowercase)
2. **Primary Keys**: Route model binding uses the model's primary key for lookups
3. **Performance**: Cache frequently accessed models in the request context
4. **Security**: Use middleware to check user permissions before granting access to bound models
5. **Error Messages**: Customize 404 messages for a better user experience

## Security Considerations

### Auto-binding is an unscoped primary-key lookup

Auto route model binding resolves a model **by primary key only**, with
no ownership or tenant scoping. A route like `GET /users/{id}` with a
controller method `show(User $user)` would return the row for *any* id —
including another user's or another tenant's — unless the controller
independently verifies ownership. This is the classic broken object-level
authorization (BOLA/IDOR) pattern.

### Explicit binding is the default

**Auto-binding is disabled by default.** A `Model` type-hint is **not**
resolved from the URL. The framework leaves the parameter for the container
to resolve, and the controller must fetch the model itself with an ownership
check:

```php
public function show(ServerRequest $request, int $id): Response
{
    $user = User::where('id', $id)
        ->where('tenant_id', $request->attribute('tenant_id'))
        ->getFirst();

    if ($user === null) {
        throw new HttpException(HttpStatus::NOT_FOUND);
    }

    return (new Response())->withJsonEnvelope(['user' => $user], 'OK', true, 200);
}
```

### Opting back into implicit binding: `MODEL_BINDING=implicit`

Projects that relied on the previous auto-binding behaviour can restore it
by setting the environment variable:

```
MODEL_BINDING=implicit
```

With `MODEL_BINDING=implicit`, a `Model` type-hint is resolved from the URL
by primary key as before. **This restores the IDOR risk** — use it only for
backward compatibility on existing applications, and ensure every
model-bound controller verifies ownership.

The framework rewrite replaces implicit binding with an opt-in `#[Bind]`
attribute (see `docs/restructure-plan.md` §15).

### Recommendation

- Leave the default (`explicit`) in place for new applications and always
  scope model lookups to the authenticated principal / tenant.
- Never rely on auto-binding alone to enforce authorization — always verify
  ownership, either in the controller or in middleware.
