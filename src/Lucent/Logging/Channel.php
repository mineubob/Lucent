<?php

namespace Lucent\Logging;

use Psr\Log\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

interface Highlighter
{
    public function shouldHighlight(string $level, string $line): bool;

    public function highlight(string $level, string $line): string;
}

class Channel implements LoggerInterface
{
    private string $channel;
    private Driver $driver;
    private bool $useColors;

    private const VALID_LEVELS = [
        LogLevel::EMERGENCY,
        LogLevel::ALERT,
        LogLevel::CRITICAL,
        LogLevel::ERROR,
        LogLevel::WARNING,
        LogLevel::NOTICE,
        LogLevel::INFO,
        LogLevel::DEBUG,
    ];

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

    /**
     * The channel's name, used both in the formatted log output and as the
     * lookup key when registered via Application::addLoggingChannel().
     */
    public function getName(): string
    {
        return $this->channel;
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

    /**
     * Interpolate context values into {placeholder} tokens.
     *
     * Follows the PSR-3 spec recommendation: a value whose key matches a
     * placeholder is stringified (if stringable); otherwise the placeholder
     * is left untouched. The "exception" key is never interpolated, so
     * exception stack traces are not inlined into the message line.
     */
    private function interpolate(string|\Stringable $message, array $context): string
    {
        if ($context === []) {
            return (string) $message;
        }

        $replace = [];
        foreach ($context as $key => $value) {
            if ($key === 'exception') {
                continue;
            }

            if (is_string($value) || is_scalar($value) || $value instanceof \Stringable) {
                $replace['{' . $key . '}'] = (string) $value;
            }
        }

        return strtr((string) $message, $replace);
    }

    /**
     * Route a log call to the matching level-specific method.
     *
     * PSR-3 requires `log()` to behave identically to the level-specific
     * method for known levels, and to throw an InvalidArgumentException for
     * unknown levels.
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if (!in_array($level, self::VALID_LEVELS, true)) {
            throw new InvalidArgumentException("Unknown log level: {$level}");
        }

        $this->write($level, $this->interpolate($message, $context));
    }

    // PSR-3 log levels
    public function emergency(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::EMERGENCY, $message, $context);
    }

    public function alert(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::ALERT, $message, $context);
    }

    public function critical(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::CRITICAL, $message, $context);
    }

    public function error(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }

    public function warning(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::WARNING, $message, $context);
    }

    public function notice(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::NOTICE, $message, $context);
    }

    public function info(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::INFO, $message, $context);
    }

    public function debug(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::DEBUG, $message, $context);
    }
}