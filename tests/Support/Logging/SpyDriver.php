<?php

namespace Tests\Support\Logging;

use Lucent\Logging\Driver;

/**
 * In-memory driver that captures every line written to it, so tests can
 * assert on the exact output a Channel produces.
 */
class SpyDriver extends Driver
{
    public array $lines = [];

    public function write(string $line): void
    {
        $this->lines[] = $line;
    }
}