<?php

namespace Lucent\Logging;

use Lucent\Facades\FileSystem;

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

    public function __construct(string $channel, Driver $driver, bool $useColors = true)
    {
        $this->channel = $channel;
        $this->driver = $driver;
        $this->useColors = $useColors && PHP_SAPI === 'cli';
    }

    private function isValidSql(string $sql): bool
    {
        $sql = trim($sql, " \t\n\r;");
        if ($sql === '')
            return false;

        $firstWord = strtoupper(strtok($sql, " \t\n\r("));

        $commands = [
            'SELECT',
            'INSERT',
            'UPDATE',
            'DELETE',
            'CREATE',
            'DROP',
            'ALTER',
            'REPLACE',
            'TRUNCATE',
            'WITH',
            'SHOW',
            'DESCRIBE'
        ];

        if (!in_array($firstWord, $commands, true)) {
            return false;
        }

        // Strict mode: enforce clause order and completeness
        $strictPatterns = [
            // SELECT must have columns + FROM + table + optional WHERE/GROUP/ORDER
            '/^SELECT\s+[\w\*,\s\(\)]+\s+FROM\s+\S+(\s+WHERE\s+.+)?(\s+GROUP\s+BY\s+.+)?(\s+ORDER\s+BY\s+.+)?$/i',

            // INSERT INTO table (...) VALUES (...)
            '/^INSERT\s+INTO\s+\S+\s*\([^)]+\)\s+VALUES\s*\([^)]+\)$/i',

            // UPDATE table SET ... WHERE ...
            '/^UPDATE\s+\S+\s+SET\s+.+(\s+WHERE\s+.+)?$/i',

            // DELETE FROM table WHERE ...
            '/^DELETE\s+FROM\s+\S+(\s+WHERE\s+.+)?$/i',

            // CREATE TABLE (IF NOT EXISTS) table (...)
            '/^CREATE\s+TABLE\s+(IF\s+NOT\s+EXISTS\s+)?[`"\w]+\s*\(.*\)$/is',
        ];

        foreach ($strictPatterns as $p) {
            if (preg_match($p, $sql))
                return true;
        }

        return false;
    }

    private function highlightSql(string $sql): string
    {
        if (!$this->isValidSql($sql)) {
            return $sql; // not SQL -> return plain
        }

        // ANSI colors
        $colors = [
            'keyword' => "\033[1;34m", // blue
            'type' => "\033[1;35m", // magenta
            'identifier' => "\033[1;36m", // cyan
            'string' => "\033[0;32m", // green
            'number' => "\033[0;36m", // light cyan
            'function' => "\033[1;33m", // yellow
            'reset' => "\033[0m"
        ];

        // Keyword set (expanded)
        $keywords = [
            'SELECT',
            'FROM',
            'WHERE',
            'INSERT',
            'INTO',
            'VALUES',
            'UPDATE',
            'SET',
            'DELETE',
            'CREATE',
            'TABLE',
            'ALTER',
            'DROP',
            'JOIN',
            'LEFT',
            'RIGHT',
            'INNER',
            'OUTER',
            'ON',
            'AS',
            'AND',
            'OR',
            'NOT',
            'NULL',
            'DISTINCT',
            'GROUP',
            'BY',
            'ORDER',
            'LIMIT',
            'OFFSET',
            'HAVING',
            'CASE',
            'WHEN',
            'THEN',
            'ELSE',
            'END',
            'IN',
            'IS',
            'LIKE',
            'UNION',
            'ALL',
            'DESC',
            'ASC',
            'IF',
            'EXISTS',
            'PRIMARY',
            'KEY',
            'DEFAULT',
            'AUTO_INCREMENT',
            'INT',
            'TINYINT',
            'VARCHAR',
            'TEXT',
            'ENGINE'
        ];

        $functions = ['COUNT', 'SUM', 'AVG', 'MIN', 'MAX', 'NOW', 'UPPER', 'LOWER', 'LENGTH'];

        $types = [
            'INT',
            'INTEGER',
            'SMALLINT',
            'BIGINT',
            'DECIMAL',
            'NUMERIC',
            'FLOAT',
            'REAL',
            'DOUBLE',
            'CHAR',
            'VARCHAR',
            'TEXT',
            'BLOB',
            'DATE',
            'TIME',
            'DATETIME',
            'TIMESTAMP',
            'BOOLEAN',
            'BOOL',
            'JSON',
            'UUID'
        ];

        // --- ORDER MATTERS ---
        // 1 Strings first
        $sql = preg_replace("/'([^']*)'/", "{$colors['string']}'\\1'{$colors['reset']}", $sql);

        // 2 Identifiers second (`table` or "table")
        $sql = preg_replace('/[`"]([^`"]+)[`"]/', "{$colors['identifier']}`\\1`{$colors['reset']}", $sql);

        // 3 Functions
        $sql = preg_replace_callback(
            '/\b(' . implode('|', $functions) . ')\s*(?=\()/i',
            fn($m) => $colors['function'] . strtoupper($m[1]) . $colors['reset'] . '(',
            $sql
        );

        // 4 Types
        $sql = preg_replace_callback(
            '/\b(' . implode('|', $types) . ')\b/i',
            fn($m) => $colors['type'] . strtoupper($m[1]) . $colors['reset'],
            $sql
        );

        // 5 Keywords
        $sql = preg_replace_callback(
            '/\b(' . implode('|', $keywords) . ')\b/i',
            fn($m) => $colors['keyword'] . strtoupper($m[1]) . $colors['reset'],
            $sql
        );

        // --- Highlight number's without breaking ANSI codes ---
        // 1 Protect existing ANSI codes
        $placeholders = [];
        $sql = preg_replace_callback('/\033\[[0-9;]*m/', function ($m) use (&$placeholders) {
            $key = "%%ANSI" . count($placeholders) . "%%";
            $placeholders[$key] = $m[0];
            return $key;
        }, $sql);

        // 2 Highlight numbers safely
        $sql = preg_replace('/\b\d+(\.\d+)?\b/', "{$colors['number']}\\0{$colors['reset']}", $sql);

        // 3 Restore ANSI sequences
        $sql = strtr($sql, $placeholders);

        return $sql;
    }

    private function formatMessage(string $level, string $message): string
    {
        $timestamp = date('Y-m-d H:i:s');
        $levelUpper = strtoupper($level);

        if ($this->useColors) {
            $levelColor = $this->levelColors[$level] ?? "\033[0m";
            $formattedMessage = $this->highlightSql($message);
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