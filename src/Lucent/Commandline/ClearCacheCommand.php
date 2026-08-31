<?php
declare(strict_types=1);


namespace Lucent\Commandline;

use Lucent\Facades\Cache;
use Lucent\Logging\ConsoleColors;

class ClearCacheCommand
{
    public static string $command = "cache:clear";

    public function clear(): string
    {
        if (Cache::clear()) {
            return ConsoleColors::FG_GREEN . "Cache cleared successfully." . ConsoleColors::RESET;
        }

        return ConsoleColors::FG_RED . "Failed to clear the cache." . ConsoleColors::RESET;
    }
}