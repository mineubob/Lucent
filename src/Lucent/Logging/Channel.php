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

        // Extract SQL starting from first SQL keyword
        if (preg_match('/\b(SELECT|INSERT|UPDATE|DELETE|CREATE|ALTER|DROP|REPLACE|TRUNCATE|WITH|SHOW|DESCRIBE|SET)\b/i', $sql, $matches, PREG_OFFSET_CAPTURE)) {
            $sql = substr($sql, $matches[0][1]);
        } else {
            return false; // No SQL keyword found
        }

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
            'DESCRIBE',
            'SET'
        ];

        $firstWord = strtoupper(strtok($sql, " \t\n\r("));
        if (!in_array($firstWord, $commands, true))
            return false;

        // -----------------------------
        // STRICT PATTERNS
        // -----------------------------
        $strictPatterns = [
            // SELECT ... FROM table [WHERE ...] [GROUP BY ...] [ORDER BY ...]
            '/^SELECT\s+.+\s+FROM\s+\S+(\s+WHERE\s+.+)?(\s+GROUP\s+BY\s+.+)?(\s+ORDER\s+BY\s+.+)?$/is',

            // INSERT INTO table (...) VALUES (...)
            '/^INSERT\s+INTO\s+\S+\s*\([^)]+\)\s+VALUES\s*\([^\)]*?\)$/is',

            // UPDATE table SET ... [WHERE ...]
            '/^UPDATE\s+\S+\s+SET\s+.+(\s+WHERE\s+.+)?$/is',

            // DELETE FROM table [WHERE ...]
            '/^DELETE\s+FROM\s+\S+(\s+WHERE\s+.+)?$/is',

            // CREATE TABLE [IF NOT EXISTS] table (...)
            '/^CREATE\s+TABLE\s+(IF\s+NOT\s+EXISTS\s+)?\S+\s*\(.*\)$/is',

            // ALTER TABLE table ...
            '/^ALTER\s+TABLE\s+\S+.+$/is',

            // DROP TABLE/INDEX [IF EXISTS] name
            '/^DROP\s+(TABLE|INDEX)\s+(IF\s+EXISTS\s+)?\S+$/is',

            // REPLACE INTO table (...) VALUES (...)
            '/^REPLACE\s+INTO\s+\S+\s*\([^)]+\)\s+VALUES\s*\([^\)]*?\)$/is',

            // TRUNCATE TABLE ...
            '/^TRUNCATE\s+TABLE\s+\S+$/is',

            // WITH ... (CTE)
            '/^WITH\s+.+$/is',

            // SHOW ...
            '/^SHOW\s+.+$/is',

            // DESCRIBE table
            '/^DESCRIBE\s+\S+$/is',

            // SET variable = value
            '/^SET\s+.+$/is',

            // PRAGMA variable = value
            '/^PRAGMA\s+.+$/is',
        ];

        foreach ($strictPatterns as $p) {
            if (preg_match($p, $sql))
                return true;
        }

        return false;
    }

    private function highlightSql(string $sql): string
    {

        $colors = [
            'keyword' => "\033[1;34m", // Blue
            'type' => "\033[1;35m", // Magenta
            'identifier' => "\033[1;37m", // White
            'string' => "\033[0;32m", // Green
            'number' => "\033[0;36m", // Cyan
            'function' => "\033[1;33m", // Yellow
            'operator' => "\033[1;37m", // White
            'comment' => "\033[0;90m", // Dark Grey
            'reset' => "\033[0m",
        ];

        $keywords = [
            'SELECT',
            'FROM',
            'WHERE',
            'INSERT',
            'INTO',
            'VALUES',
            'UPDATE',
            'SET',
            'PRAGMA',
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
            'CHECK',
            'CONSTRAINT',
            'AUTO_INCREMENT',
            'AUTOINCREMENT'
        ];

        $types = [
            'INT',
            'INTEGER',
            'SMALLINT',
            'TINYINT',
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

        $functions = [
            'COUNT',
            'SUM',
            'AVG',
            'MIN',
            'MAX',
            'NOW',
            'CURRENT_TIMESTAMP',
            'UPPER',
            'LOWER',
            'LENGTH',
            'ABS',
            'ROUND',
            'RANDOM'
        ];

        // Protect existing ANSI codes
        $placeholders = [];
        $sql = preg_replace_callback('/\033\[[0-9;]*m/', function ($m) use (&$placeholders) {
            $key = "%%ANSI" . count($placeholders) . "%%";
            $placeholders[$key] = $m[0];
            return $key;
        }, $sql);

        // Comments
        $sql = preg_replace_callback('/\/\*.*?\*\//s', fn($m) => $colors['comment'] . $m[0] . $colors['reset'], $sql);
        $sql = preg_replace_callback('/--.*$/m', fn($m) => $colors['comment'] . $m[0] . $colors['reset'], $sql);

        // Strings
        $sql = preg_replace("/'([^']*)'/", $colors['string'] . "'$1'" . $colors['reset'], $sql);

        // Identifiers
        $sql = preg_replace('/[`"]([^`"]+)[`"]/', $colors['identifier'] . '`$1`' . $colors['reset'], $sql);


        // Special handling for SET and PRAGMA
        foreach (['SET', 'PRAGMA'] as $cmd) {
            if (preg_match("/^\s*$cmd\s+/i", $sql)) {
                $sql = preg_replace_callback(
                    "/$cmd\s+(.*)/i",
                    function ($m) use ($colors, $cmd) {
                        $assignments = $m[1];
                        // Highlight numbers
                        $assignments = preg_replace(
                            '/\b\d+(\.\d+)?\b/',
                            $colors['number'] . '$0' . $colors['reset'],
                            $assignments
                        );
                        // Highlight variables
                        $assignments = preg_replace(
                            '/(\b[A-Z_][A-Z0-9_]*\b|@[A-Za-z0-9_]+)/i',
                            $colors['identifier'] . '$1' . $colors['reset'],
                            $assignments
                        );
                        // Highlight ON/OFF/TRUE/FALSE for PRAGMA
                        if ($cmd === 'PRAGMA') {
                            $assignments = preg_replace(
                                '/\b(ON|OFF|TRUE|FALSE)\b/i',
                                $colors['keyword'] . '$0' . $colors['reset'],
                                $assignments
                            );
                        }
                        // Highlight operators
                        $assignments = preg_replace(
                            '/=/',
                            $colors['operator'] . '=' . $colors['reset'],
                            $assignments
                        );
                        return $colors['keyword'] . $cmd . $colors['reset'] . ' ' . $assignments;
                    },
                    $sql
                );
            }
        }

        // Functions
        $sql = preg_replace_callback(
            '/\b(' . implode('|', $functions) . ')\s*(?=\()/i',
            fn($m) => $colors['function'] . strtoupper($m[1]) . $colors['reset'] . '(',
            $sql
        );

        // Types
        $sql = preg_replace_callback(
            '/\b(' . implode('|', $types) . ')\b/i',
            fn($m) => $colors['type'] . strtoupper($m[1]) . $colors['reset'],
            $sql
        );

        // Keywords
        $sql = preg_replace_callback(
            '/\b(' . implode('|', $keywords) . ')\b/i',
            fn($m) => $colors['keyword'] . strtoupper($m[1]) . $colors['reset'],
            $sql
        );

        // Operators
        $sql = preg_replace(
            '/(\=|<|>|\!|\+|\-|\*|\/|\(|\)|,)/',
            $colors['operator'] . '$1' . $colors['reset'],
            $sql
        );

        // Protect ANSI codes before number pass.
        $sql = preg_replace_callback('/\033\[[0-9;]*m/', function ($m) use (&$placeholders) {
            $key = "%%ANSI" . count($placeholders) . "%%";
            $placeholders[$key] = $m[0];
            return $key;
        }, $sql);

        // Numbers
        $sql = preg_replace(
            '/\b\d+(\.\d+)?\b/',
            $colors['number'] . '$0' . $colors['reset'],
            $sql
        );

        // Placeholders
        $sql = preg_replace(
            '/\?/',
            $colors['number'] . '?' . $colors['reset'],
            $sql
        );

        // Restore ANSI codes
        $sql = strtr($sql, $placeholders);

        return $sql;
    }

    private function highlightLine(string $line): string
    {
        // Match SQL statements
        $sqlPattern = '/\b(SELECT|INSERT|UPDATE|DELETE|CREATE|ALTER|DROP|REPLACE|TRUNCATE|WITH|SHOW|DESCRIBE|SET|PRAGMA)\b.*?(?=(\bSELECT|\bINSERT|\bUPDATE|\bDELETE|\bCREATE|\bALTER|\bDROP|\bREPLACE|\bTRUNCATE|\bWITH|\bSHOW|\bDESCRIBE|\bSET\b|\bPRAGMA\b|$))/is';

        $line = preg_replace_callback($sqlPattern, function ($matches) {
            $sql = $matches[0];
            return $this->isValidSql($sql) ? $this->highlightSql($sql) : $sql;
        }, $line);

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