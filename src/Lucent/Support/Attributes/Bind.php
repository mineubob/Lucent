<?php

declare(strict_types=1);

namespace Lucent\Support\Attributes;

use Attribute;

/**
 * Opt-in route model binding for controller parameters.
 *
 * A parameter type-hinted to a Radiant {@see \BlueprintAU\Radiant\Model}
 * is resolved from the URL ONLY when it carries this attribute — a bare
 * Model type-hint is never auto-bound (the IDOR fix; the old
 * MODEL_BINDING=implicit gate is gone).
 *
 * The route variable is always the parameter name. What the attribute
 * controls is HOW the lookup key is resolved:
 *
 * - `resolve`: a column name (bind that column to the route variable) or a
 *   callable returning the full `[column => value]` map (required for
 *   composite primary keys).
 * - `scope`: a callable applied to the query before execution — the
 *   ownership/tenant check.
 * - `connection`: a named connection, or a callable that returns the
 *   connection name — the lookup runs on that connection and the previous
 *   active connection is restored afterwards.
 *
 * PHP attributes only accept CONSTANT expressions, so callables must be
 * passed as invokable class-strings (`SomeScope::class`) or
 * `[Class::class, 'method']` arrays of constants — a closure or `fn()` in
 * an attribute argument is a compile error. Closures remain supported when
 * a Bind instance is constructed programmatically.
 *
 * Exact callable signatures (enforced by convention, not by PHP):
 *
 * - resolve: `callable(array $vars, ServerRequestInterface $request): array<string, mixed>`
 * - scope:   `callable(ModelQueryBuilder $query, mixed $value, array $vars, ServerRequestInterface $request): ModelQueryBuilder`
 * - connection: `callable(array $vars, ServerRequestInterface $request): string`
 *
 * ```php
 * public function show(#[Bind] User $user): Response { ... }              // by PK
 * public function show(#[Bind(resolve: 'slug')] User $user): Response {}  // by slug
 * public function update(
 *     #[Bind(scope: TenantScope::class)]
 *     User $user,
 * ): Response { ... }
 * public function edit(
 *     #[Bind(scope: [TenantScope::class, 'apply'])]
 *     User $user,
 * ): Response { ... }
 * ```
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class Bind
{
    /**
     * @param string|callable|null $resolve Column name, or
     *        callable(array $vars, ServerRequestInterface $request): array<string, mixed>
     * @param callable|null $scope
     *        callable(ModelQueryBuilder $query, mixed $value, array $vars, ServerRequestInterface $request): ModelQueryBuilder
     * @param string|callable|null $connection Named connection, or
     *        callable(array $vars, ServerRequestInterface $request): string
     */
    public function __construct(
        public readonly mixed $resolve = null,
        public readonly mixed $scope = null,
        public readonly mixed $connection = null,
    ) {
    }
}
