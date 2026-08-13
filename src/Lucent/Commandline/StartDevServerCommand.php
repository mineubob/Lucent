<?php

namespace Lucent\Commandline;

use Lucent\Facades\App;
use Lucent\Facades\FileSystem;
use Lucent\Logging\ConsoleColors;

class StartDevServerCommand
{

    public static string $command = "serve";

    public function start(array $options = []): string
    {
        // Configurable values. Precedence: CLI option > env var > default.
        $portExplicit = $options['port'] ??App::env('SERVER_PORT');
        $port = $portExplicit ?? 8080;
        $host = $options['host'] ?? App::env('SERVER_HOST', '127.0.0.1');
        $docRoot = $options['docroot'] ?? App::env('SERVER_DOCROOT', 'public');
        $router = $options['router'] ?? App::env('SERVER_ROUTER', 'public/index.php');
        $tries = $options['tries'] ?? App::env('SERVER_TRIES', 10);
        $noRestart = $options['no-restart'] ?? filter_var(App::env('SERVER_NO_RESTART', false), FILTER_VALIDATE_BOOL);

        // Only auto-increment the port when it wasn't explicitly configured
        // (mirrors Laravel, which checks is_null($input->getOption('port'))).
        $portIsExplicit = $portExplicit !== null;

        if (!is_numeric($port)) {
            return "Invalid port number provided, must be a 'number'";
        }

        echo ConsoleColors::FG_CYAN . "Lucent Development Server Starting..." . ConsoleColors::RESET . "\n";
        echo ConsoleColors::FG_YELLOW . "  Press Ctrl+C to stop the server" . ConsoleColors::RESET . "\n";
        echo ConsoleColors::FG_BLUE . str_repeat("─", 50) . ConsoleColors::RESET . "\n";

        // Resolve docroot and router against the project root.
        $docRootPath = $this->resolvePath($docRoot);
        $routerPath = $this->resolvePath($router);

        if (!is_dir($docRootPath)) {
            return ConsoleColors::FG_RED . "✗ Document root not found: {$docRootPath}" . ConsoleColors::RESET;
        }

        if (!is_file($routerPath)) {
            return ConsoleColors::FG_RED . "✗ Router script not found: {$routerPath}" . ConsoleColors::RESET;
        }

        // Track .env so we can restart the server when it changes (mirrors
        // Laravel). Disabled via --no-restart / SERVER_NO_RESTART.
        $envFile = FileSystem::rootPath() . DIRECTORY_SEPARATOR . '.env';
        $envLastModified = file_exists($envFile) ? filemtime($envFile) : null;

        // Auto-increment the port if the requested one is busy (mirrors
        // Laravel's --tries). Only applies when the port wasn't explicitly set.
        $portOffset = 0;
        $restart = true;

        while ($restart) {
            $restart = false;
            $currentPort = (int) $port + $portOffset;

            // Build the PHP built-in server command. The router script
            // (public/index.php) serves static files directly and forwards
            // everything else to Lucent, so routes like /users work.
            $command = sprintf(
                'php -S %s:%d -t %s %s',
                escapeshellarg($host),
                $currentPort,
                escapeshellarg($docRootPath),
                escapeshellarg($routerPath)
            );

            // proc_open streams output in real-time AND lets us react to the
            // child process (unlike passthru, which blocks the parent entirely).
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

            if (!is_resource($process)) {
                return ConsoleColors::FG_RED . "✗ Failed to start the server" . ConsoleColors::RESET;
            }

            // Non-blocking reads so we never stall on a partial line.
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);

            $envChanged = false;

            // Stream stdout/stderr until the server exits. stream_select()
            // waits efficiently for output (or process exit) instead of
            // busy-waiting.
            while (true) {
                // Drain whatever output is currently available.
                foreach ([1, 2] as $fd) {
                    $chunk = stream_get_contents($pipes[$fd]);
                    if ($chunk !== false && $chunk !== '') {
                        echo $chunk;
                    }
                }

                $status = proc_get_status($process);
                if (!$status['running']) {
                    break;
                }

                // Restart the server if .env changed on disk.
                if (!$noRestart && $envLastModified !== null) {
                    clearstatcache(false, $envFile);
                    $current = filemtime($envFile);
                    if ($current > $envLastModified) {
                        $envLastModified = $current;
                        $envChanged = true;
                        break;
                    }
                }

                // Block until there is more output to read or the process exits.
                $read = [$pipes[1], $pipes[2]];
                $write = null;
                $except = null;
                stream_select($read, $write, $except, 1);
            }

            // Capture the exit status BEFORE closing so we can distinguish a
            // signal kill (Ctrl+C) from a normal exit or a bind failure.
            $status = proc_get_status($process);
            $exitCode = $status['exitcode'];
            $signaled = $status['signaled'];
            proc_close($process);

            if ($envChanged) {
                echo "\n" . ConsoleColors::FG_YELLOW . "Environment modified. Restarting server..." . ConsoleColors::RESET . "\n";
                $restart = true;
                continue;
            }

            // If the user pressed Ctrl+C (process killed by a signal), stop
            // cleanly — do NOT try another port.
            if ($signaled) {
                break;
            }

            // If the server exited immediately with an error, the port may be
            // busy. Try the next port up to the --tries limit — but only when
            // the port wasn't explicitly configured.
            if (!$portIsExplicit && $exitCode !== 0 && $portOffset < $tries - 1) {
                $portOffset++;
                echo ConsoleColors::FG_YELLOW . "Port {$currentPort} unavailable, trying " . ((int) $port + $portOffset) . "..." . ConsoleColors::RESET . "\n";
                $restart = true;
                continue;
            }

            break;
        }

        echo "\n" . ConsoleColors::FG_YELLOW . "Server stopped" . ConsoleColors::RESET . "\n";
        return "";
    }

    /**
     * Resolve a path against the project root unless it is already absolute.
     */
    private function resolvePath(string $path): string
    {
        if (FileSystem::isAbsolute($path)) {
            return $path;
        }
        return FileSystem::rootPath() . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);
    }
}