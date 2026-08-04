<?php

namespace Lucent\Database;

use Psr\Log\LoggerInterface;

/**
 * @deprecated Use Psr\Log\LoggerInterface directly instead.
 *
 * Kept as a deprecated alias for projects that still type-hint
 * Lucent\Database\DatabaseLogger.
 *
 * Note: since this now extends PSR-3 LoggerInterface, existing implementations
 * of the legacy (pre-PSR-3) DatabaseLogger must be updated to implement the full
 * PSR-3 interface.
 */
interface DatabaseLogger extends LoggerInterface {}
