<?php
declare(strict_types=1);


namespace Lucent\Logging\Drivers;

use Lucent\Logging\Driver;

class CliDriver extends Driver
{
    public function write(string $line): void
    {
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, $line);
        }
    }
}