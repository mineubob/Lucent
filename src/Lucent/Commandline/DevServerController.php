<?php

namespace Lucent\Commandline;

use Lucent\Facades\FileSystem;
use Lucent\Logging\ConsoleColors;

class DevServerController
{
    public function start(array $options = []): string
    {
        $port = $options['port'] ?? 8080;

        if (!is_numeric($port)) {
            return "Invalid port number provided, must be a 'number'";
        }

        echo ConsoleColors::FG_CYAN . "Lucent Development Server Starting..." . ConsoleColors::RESET . "\n";
        echo ConsoleColors::FG_YELLOW . "  Press Ctrl+C to stop the server" . ConsoleColors::RESET . "\n";
        echo ConsoleColors::FG_BLUE . str_repeat("─", 50) . ConsoleColors::RESET . "\n";

        $docRoot = FileSystem::rootPath() . DIRECTORY_SEPARATOR . "public";

        // Build the PHP built-in server command
        $command = sprintf(
            'php -S localhost:%d -t %s',
            $port,
            escapeshellarg($docRoot)
        );

        // Use passthru to stream output in real-time
        passthru($command, $exitCode);

        // If we get here, the server stopped
        if ($exitCode !== 0) {
            echo "\n" . ConsoleColors::FG_RED . "✗ Server stopped with error code: {$exitCode}" . ConsoleColors::RESET . "\n";
        } else {
            echo "\n" . ConsoleColors::FG_YELLOW . "Server stopped" . ConsoleColors::RESET . "\n";
        }

        return "";
    }
}