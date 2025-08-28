<?php

namespace Lucent\Logging\Drivers;

use Lucent\Facades\FileSystem;
use Lucent\Logging\Driver;

class FileDriver extends Driver
{
    private string $path;

    public function __construct(string $path)
    {
        $this->path = $path;
    }

    public function write(string $line): void
    {
        $logDir = FileSystem::rootPath() . DIRECTORY_SEPARATOR ."logs";

        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $logPath = $logDir . DIRECTORY_SEPARATOR . $this->path;

        // Try to open file and handle any errors
        $file = @fopen($logPath, "a");
        if ($file === false) {
            error_log("Failed to open log file: " . $logPath);
            return;
        }

        // Strip ANSI color codes for file logging
        fwrite($file, preg_replace('/\033\[[0-9;]*m/', '', $line));
        fclose($file);
    }
}