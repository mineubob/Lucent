<?php

namespace Lucent\Database;

use Psr\Log\LoggerInterface;

/**
 * @deprecated Use Psr\Log\LoggerInterface directly instead.
 *
 * This interface is kept for backward compatibility with code that references
 * Lucent\Database\DatabaseLogger. It is no longer used by the framework —
 * Database::setLogger() now accepts any PSR-3 logger implementation.
 */
interface DatabaseLogger extends LoggerInterface
{
}