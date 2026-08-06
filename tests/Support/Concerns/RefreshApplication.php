<?php

namespace Tests\Support\Concerns;

use Lucent\Application;

/**
 * Resets the Lucent application singleton to a clean state.
 *
 * `Application::reset()` replaces the singleton with a fresh instance
 * (preserving registered loggers). Tests that need a clean application
 * between cases — e.g. route-group and custom-error-page tests — use this
 * instead of calling `Application::reset()` inline.
 */
trait RefreshApplication
{
    /**
     * Reset the application singleton.
     */
    protected static function refreshApplication(): void
    {
        Application::reset();
    }

    /**
     * Reset the application and boot it (auto-loading routes/commands).
     */
    protected static function refreshAndBootApplication(): void
    {
        Application::reset();
        Application::getInstance()->boot();
    }
}