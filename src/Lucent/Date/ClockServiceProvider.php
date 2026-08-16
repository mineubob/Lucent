<?php

namespace Lucent\Date;

use Lucent\Container\ServiceProvider;
use Psr\Clock\ClockInterface;

/**
 * Registers the shared PSR-20 clock so services can type-hint
 * {@see ClockInterface} for constructor injection.
 */
class ClockServiceProvider extends ServiceProvider
{
    /**
     * Register the shared clock instances.
     *
     * The clock is immutable and safe to share, so both the interface and the
     * concrete class resolve to the same local clock instance.
     */
    public function register(): void
    {
        // Register the concrete clock as the shared instance and alias the
        // PSR interface to it, so both identifiers resolve to the same clock.
        $this->instance(Clock::class, Clock::local());
        $this->alias(Clock::class, ClockInterface::class);
    }
}
