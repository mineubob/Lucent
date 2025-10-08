<?php

namespace Lucent\Logging;

class SqlHighlighter implements Highlighter
{
    /**
     * Array of valid SQL patterns.
     * @var string[]
     */
    private array $sqlPatterns = [
        // SELECT <value>
        '/SELECT\s+(?!.*\b(FROM|WHERE|GROUP|ORDER|HAVING|LIMIT|OFFSET|JOIN)\b)+/is',

        // SELECT ... FROM <table> [[INNER|LEFT|RIGHT|FULL] [OUTER] JOIN .. ON ..] [WHERE ...] [GROUP BY ...] [ORDER BY ...]
        '/SELECT\s+.+\s+FROM\s+\S+(?:\s+(?:(?:INNER|LEFT|RIGHT|FULL)(?:\s+OUTER)?\s+)?JOIN\s+\S+\s+ON\s+.+?)*(\s+WHERE\s+.+)?(\s+GROUP\s+BY\s+.+)?(\s+ORDER\s+BY\s+.+)?(\s+LIMIT\s+\d+)?(\s+OFFSET\s+\d+)?/is',

        // INSERT INTO table (...) VALUES (...)
        '/INSERT\s+INTO\s+\S+\s*\([^)]+\)\s+VALUES\s*\([^\)]*?\)/is',

        // UPDATE table SET ... [WHERE ...]
        '/UPDATE\s+\S+\s+SET\s+.+(\s+WHERE\s+.+)?/is',

        // DELETE FROM table [WHERE ...]
        '/DELETE\s+FROM\s+\S+(\s+WHERE\s+.+)?/is',

        // CREATE TABLE [IF NOT EXISTS] table (...)
        '/CREATE\s+TABLE\s+(IF\s+NOT\s+EXISTS\s+)?\S+\s*\(.*\)/is',

        // ALTER TABLE table ...
        '/ALTER\s+TABLE\s+\S+.+/is',

        // DROP TABLE/INDEX [IF EXISTS] name
        '/DROP\s+(TABLE|INDEX)\s+(IF\s+EXISTS\s+)?\S+/is',

        // REPLACE INTO table (...) VALUES (...)
        '/REPLACE\s+INTO\s+\S+\s*\([^)]+\)\s+VALUES\s*\([^\)]*?\)/is',

        // TRUNCATE TABLE ...
        '/TRUNCATE\s+TABLE\s+\S+/is',

        // WITH ... (CTE)
        '/WITH\s+.+/is',

        // SHOW ...
        '/SHOW\s+.+/is',

        // DESCRIBE table
        '/DESCRIBE\s+\S+/is',

        // SET variable = value
        '/SET\s+.+/is',

        // PRAGMA variable = value
        '/PRAGMA\s+\S+(\s*=\s*\S+)?/is',
    ];

    /**
     * Array of colors to use for highlighting.
     * @var string[]
     */
    private $colors = [
        'keyword' => "\033[1;34m", // Blue
        'type' => "\033[1;35m", // Magenta
        'identifier' => "\033[1;37m", // White
        'string' => "\033[0;32m", // Green
        'number' => "\033[0;36m", // Cyan
        'function' => "\033[1;33m", // Yellow
        'operator' => "\033[1;37m", // White
        'comment' => "\033[0;90m", // Dark Grey
        'placeholder' => "\033[1;37m", // White
        'reset' => "\033[0m",
    ];

    /**
     * Array of valid SQL keywords.
     * @var string[]
     */
    private array $keywords = [
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
        'DESCRIBE',
        'SHOW',
        'ALTER',
        'DROP',
        'JOIN',
        'LEFT',
        'RIGHT',
        'INNER',
        'OUTER',
        'FULL',
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

    /**
     * Array of valid SQL types.
     * @var string[]
     */
    private $types = [
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

    /**
     * Array of valid SQL functions.
     * @var string[]
     */
    private $functions = [
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

    public function shouldHighlight(string $level, string $line): bool
    {
        foreach ($this->sqlPatterns as $pattern) {
            if (preg_match($pattern, $line)) {
                return true;
            }
        }

        return false;
    }

    private function highlightCore(string $sql): string
    {
        // Protect existing ANSI codes
        $placeholders = [];
        $sql = preg_replace_callback('/\033\[[0-9;]*m/', function ($m) use (&$placeholders) {
            $key = "%%ANSI" . count($placeholders) . "%%";
            $placeholders[$key] = $m[0];
            return $key;
        }, $sql);

        // Comments
        $sql = preg_replace_callback('/\/\*.*?\*\//s', fn($m) => $this->colors['comment'] . $m[0] . $this->colors['reset'], $sql);
        $sql = preg_replace_callback('/--.*$/m', fn($m) => $this->colors['comment'] . $m[0] . $this->colors['reset'], $sql);

        // Strings (protected from other stages)
        $sql = preg_replace_callback(
            '/(\'[^\']*\'|"[^"]*")/',
            function ($m) use (&$placeholders) {
                $key = "%%STR" . count($placeholders) . "%%";
                $placeholders[$key] = $this->colors['string'] . $m[0] . $this->colors['reset'];
                return $key;
            },
            $sql
        );

        // Identifiers (protected from other stages)
        $sql = preg_replace_callback(
            '/[`"]([^`"]+)[`"]/',
            function ($m) use (&$placeholders) {
                $key = "%%IDENT" . count($placeholders) . "%%";
                $placeholders[$key] = $this->colors['identifier'] . $m[0] . $this->colors['reset'];
                return $key;
            },
            $sql
        );

        // Special handling for SET and PRAGMA
        foreach (['SET', 'PRAGMA'] as $cmd) {
            if (preg_match("/^\s*$cmd\s+/i", $sql)) {
                $sql = preg_replace_callback(
                    "/$cmd\s+(.*)/i",
                    function ($m) use ($cmd) {
                        $assignments = $m[1];
                        // Highlight numbers
                        $assignments = preg_replace(
                            '/\b\d+(\.\d+)?\b/',
                            $this->colors['number'] . '$0' . $this->colors['reset'],
                            $assignments
                        );
                        // Highlight variables
                        $assignments = preg_replace(
                            '/(\b[A-Z_][A-Z0-9_]*\b|@[A-Za-z0-9_]+)/i',
                            $this->colors['identifier'] . '$1' . $this->colors['reset'],
                            $assignments
                        );
                        // Highlight ON/OFF/TRUE/FALSE for PRAGMA
                        if ($cmd === 'PRAGMA') {
                            $assignments = preg_replace(
                                '/\b(ON|OFF|TRUE|FALSE)\b/i',
                                $this->colors['keyword'] . '$0' . $this->colors['reset'],
                                $assignments
                            );
                        }
                        // Highlight operators
                        $assignments = preg_replace(
                            '/=/',
                            $this->colors['operator'] . '=' . $this->colors['reset'],
                            $assignments
                        );
                        return $this->colors['keyword'] . $cmd . $this->colors['reset'] . ' ' . $assignments;
                    },
                    $sql
                );
            }
        }

        // Functions
        $sql = preg_replace_callback(
            '/\b(' . implode('|', $this->functions) . ')\s*(?=\()/i',
            fn($m) => $this->colors['function'] . strtoupper($m[1]) . $this->colors['reset'],
            $sql
        );

        // Types
        $sql = preg_replace_callback(
            '/\b(' . implode('|', $this->types) . ')\b/i',
            fn($m) => $this->colors['type'] . strtoupper($m[1]) . $this->colors['reset'],
            $sql
        );

        // Keywords
        $sql = preg_replace_callback(
            '/\b(' . implode('|', $this->keywords) . ')\b/i',
            fn($m) => $this->colors['keyword'] . strtoupper($m[1]) . $this->colors['reset'],
            $sql
        );

        // Operators
        $sql = preg_replace(
            '/(\=|<|>|\!|\+|\-|\*|\/|\(|\)|,)/',
            $this->colors['operator'] . '$1' . $this->colors['reset'],
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
            $this->colors['number'] . '$0' . $this->colors['reset'],
            $sql
        );

        // Placeholders
        $sql = preg_replace(
            '/\?/',
            $this->colors['placeholder'] . '?' . $this->colors['reset'],
            $sql
        );

        // Restore ANSI codes
        $sql = strtr($sql, $placeholders);

        return $sql;
    }

    public function highlight(string $level, string $line): string
    {
        foreach ($this->sqlPatterns as $pattern) {
            $line = preg_replace_callback(
                $pattern,
                fn($matches) => $this->highlightCore($matches[0]),
                $line
            );
        }

        return $line;
    }
}