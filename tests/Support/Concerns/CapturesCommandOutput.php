<?php

namespace Tests\Support\Concerns;

use Lucent\Application;
use Lucent\Facades\CommandLine;

/**
 * Captures command-line output for tests that invoke console commands.
 *
 * `CommandLine::execute()` only returns the command's output when capture
 * mode is on. This is a static flag that other tests may toggle, so tests
 * that rely on it should enable it explicitly to stay order-independent.
 */
trait CapturesCommandOutput
{
    /**
     * Reset the console router and enable command output capture.
     */
    protected function captureCommandOutput(): void
    {
        Application::getInstance()->consoleRouter->reset();
        CommandLine::captureOutput();
    }
}