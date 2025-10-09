<?php

namespace Lucent\Logging\Drivers;

use Lucent\Logging\Driver;

class TeeDriver extends Driver
{
    private Driver $left;
    private Driver $right;

    public function __construct(Driver $left, Driver $right)
    {
        $this->left = $left;
        $this->right = $right;
    }

    public function write(string $line): void
    {
        // Write to left driver first.
        $this->left->write($line);

        // Then write to right driver.
        $this->right->write($line);
    }
}