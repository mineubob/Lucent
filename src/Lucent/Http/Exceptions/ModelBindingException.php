<?php

declare(strict_types=1);

namespace Lucent\Http\Exceptions;

use Lucent\Http\HttpStatus;

/**
 * Thrown when a #[Bind] route model binding cannot resolve a model.
 *
 * Extends {@see HttpException} so the standard HTTP error pipeline renders
 * it (NOT_FOUND by default), while remaining distinguishable from any other
 * 404 — middleware, loggers and exception reporters can catch this type
 * specifically to identify a failed binding rather than a missing route.
 *
 * Carries the model class and the attempted lookup keys for diagnostics.
 */
final class ModelBindingException extends HttpException
{
    /**
     * @param class-string $model The model class that failed to resolve
     * @param array<string, mixed> $keys The [column => value] lookup attempted
     * @param string|null $reason Optional detail (e.g. "scope filtered the row")
     */
    public function __construct(
        string $model,
        array $keys = [],
        ?string $reason = null,
    ) {
        $detail = $reason !== null && $reason !== '' ? " ({$reason})" : '';

        parent::__construct(
            HttpStatus::NOT_FOUND,
            "Route model binding failed: no {$model} matches "
            . json_encode($keys, JSON_UNESCAPED_SLASHES) . $detail,
        );
    }
}
