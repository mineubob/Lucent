<?php

namespace Lucent\Logging;

interface Highlighter
{
    public function shouldHighlight(string $level, string $line): bool;

    public function highlight(string $level, string $line): string;
}

class Channel
{
    private string $channel;
    private Driver $driver;
    private bool $useColors;

    // Simplified color scheme to start with
    private array $levelColors = [
        'emergency' => "\033[1;37;41m", // Bold white on red
        'alert' => "\033[1;31m",    // Bold red
        'critical' => "\033[0;31m",    // Red
        'error' => "\033[0;31m",    // Red
        'warning' => "\033[0;33m",    // Yellow
        'notice' => "\033[0;36m",    // Cyan
        'info' => "\033[0;32m",    // Green
        'debug' => "\033[0;37m"     // White
    ];

    /**
     * A list of highlighter's.
     * @var Highlighter[]
     */
    private array $highlighters;

    public function __construct(string $channel, Driver $driver, bool $useColors = true)
    {
        $this->channel = $channel;
        $this->driver = $driver;
        $this->useColors = $useColors && PHP_SAPI === 'cli';
        $this->highlighters = [
            new SqlHighlighter(),
        ];
    }

    private function highlightLine(string $line): string
    {
        foreach ($this->highlighters as $highlighter) {
            if (!($highlighter instanceof Highlighter)) {
                continue;
            }

            if ($highlighter->shouldHighlight($line, $line)) {
                $line = $highlighter->highlight($line, $line);
            }
        }

        return $line;
    }

    private function formatMessage(string $level, string $message): string
    {
        $timestamp = date('Y-m-d H:i:s');
        $levelUpper = strtoupper($level);

        if ($this->useColors) {
            $levelColor = $this->levelColors[$level] ?? "\033[0m";
            $formattedMessage = $this->highlightLine($message);
            return sprintf(
                "[%s] %s%s\033[0m | %s | %s\n",
                $timestamp,
                $levelColor,
                $levelUpper,
                $this->channel,
                $formattedMessage
            );
        }

        return sprintf(
            "[%s] %s | %s | %s\n",
            $timestamp,
            $levelUpper,
            $this->channel,
            $message
        );
    }

    private function write(string $level, string $message): void
    {
        $this->driver->write($this->formatMessage($level, $message));
    }

    // PSR-3 log levels
    public function emergency(string $message): void
    {
        $this->write('emergency', $message);
    }

    public function alert(string $message): void
    {
        $this->write('alert', $message);
    }

    public function critical(string $message): void
    {
        $this->write('critical', $message);
    }

    public function error(string $message): void
    {
        $this->write('error', $message);
    }

    public function warning(string $message): void
    {
        $this->write('warning', $message);
    }

    public function notice(string $message): void
    {
        $this->write('notice', $message);
    }

    public function info(string $message): void
    {
        $this->write('info', $message);
    }

    public function debug(string $message): void
    {
        $this->write('debug', $message);
    }
}