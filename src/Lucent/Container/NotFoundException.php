<?php

namespace Lucent\Container;

use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

/**
 * Thrown when a container identifier has no registered entry.
 *
 * Carries the identifier that was requested, so callers (and logs) can
 * see exactly which identifier failed to resolve.
 */
class NotFoundException extends RuntimeException implements NotFoundExceptionInterface
{
    /**
     * The identifier that could not be resolved.
     */
    public private(set) string $id;

    /**
     * @param string $id The identifier that could not be resolved
     */
    public function __construct(string $id)
    {
        $this->id = $id;
        parent::__construct(sprintf('No service is registered for identifier "%s".', $id));
    }
}
