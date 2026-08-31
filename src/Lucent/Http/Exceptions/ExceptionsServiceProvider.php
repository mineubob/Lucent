<?php
declare(strict_types=1);


namespace Lucent\Http\Exceptions;

use Lucent\Container\ServiceProvider;

/**
 * Registers the shared exception manager as a lazy container singleton.
 *
 * The provider is deferred: it is only loaded (and the manager only
 * instantiated) on the first exceptions() call.
 */
class ExceptionsServiceProvider extends ServiceProvider
{
    /**
     * Whether the provider is deferred until the manager is first resolved.
     *
     * @var bool
     */
    protected bool $defer = true;

    /**
     * Register the exception manager as a lazy singleton.
     */
    public function register(): void
    {
        $this->singleton(Exceptions::class);
    }

    /**
     * The service this provider provides, used for deferred loading.
     *
     * @return array<int, string> The provided service identifiers
     */
    public function provides(): array
    {
        return [Exceptions::class];
    }
}
