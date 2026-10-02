<?php
namespace App\Middleware;

/**
 * Invokable #[Bind] scope — only resolves a TestUser whose full_name is
 * 'Jane Doe'. Invoked as `new self()($query, $value, $vars, $context)`.
 */
class JaneDoeScope
{
    public function __invoke($query, mixed $value, array $vars, mixed $context)
    {
        return $query->where('full_name', '=', 'Jane Doe');
    }

    /**
     * Method-form scope — used via #[Bind(scope: [JaneDoeScope::class, 'apply'])]
     * to prove [Class::class, 'method'] array callables work in attributes.
     */
    public static function apply($query, mixed $value, array $vars, mixed $context)
    {
        return $query->where('full_name', '=', 'Jane Doe');
    }
}
