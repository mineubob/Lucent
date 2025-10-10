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
        '/\bSELECT\b\s+(?!.*\b(FROM|WHERE|GROUP|ORDER|HAVING|LIMIT|OFFSET|JOIN)\b)+/is',

        // SELECT ... FROM <table> [[INNER|LEFT|RIGHT|FULL] [OUTER] JOIN .. ON ..] [WHERE ...] [GROUP BY ...] [ORDER BY ...]
        '/\bSELECT\b\s+.+?\s+\bFROM\b\s+\S+(?:\s+(?:(?:INNER|LEFT|RIGHT|FULL)(?:\s+OUTER)?\s+)?\bJOIN\b\s+\S+\s+\bON\b\s+.+?)*(\s+\bWHERE\b\s+.+)?(\s+\bGROUP\s+\bBY\b\s+.+)?(\s+\bORDER\s+\bBY\b\s+.+)?(\s+\bLIMIT\b\s+\d+)?(\s+\bOFFSET\b\s+\d+)?/is',

        // INSERT INTO table (...) VALUES (...)
        '/\bINSERT\b\s+\bINTO\b\s+\S+\s*\([^)]+\)\s+\bVALUES\b\s*\([^)]+?\)/is',

        // UPDATE table SET ... [WHERE ...]
        '/\bUPDATE\b\s+\S+\s+\bSET\b\s+.+?(?:\s+\bWHERE\b\s+.+)?/is',

        // DELETE FROM table [WHERE ...]
        '/\bDELETE\b\s+\bFROM\b\s+\S+(?:\s+\bWHERE\b\s+.+)?/is',

        // CREATE TABLE [IF NOT EXISTS] table (...)
        '/\bCREATE\b\s+\bTABLE\b\s+(?:\bIF\b\s+\bNOT\b\s+\bEXISTS\b\s+)?\S+\s*\(.*?\)/is',

        // ALTER TABLE table ...
        '/\bALTER\b\s+\bTABLE\b\s+\S+.+/is',

        // DROP TABLE/INDEX [IF EXISTS] name
        '/\bDROP\b\s+(?:\bTABLE\b|\bINDEX\b)\s+(?:\bIF\b\s+\bEXISTS\b\s+)?\S+/is',

        // REPLACE INTO table (...) VALUES (...)
        '/\bREPLACE\b\s+\bINTO\b\s+\S+\s*\([^)]+\)\s+\bVALUES\b\s*\([^)]+?\)/is',

        // TRUNCATE TABLE ...
        '/\bTRUNCATE\b\s+\bTABLE\b\s+\S+/is',

        // WITH ... (CTE)
        '/\bWITH\b\s+.+/is',

        // SHOW ...
        '/\bSHOW\b\s+.+/is',

        // DESCRIBE table
        '/\bDESCRIBE\b\s+\S+/is',

        // SET variable = value (not part of column names)
        '/\bSET\b\s+[^;]+/is',

        // PRAGMA variable = value
        '/\bPRAGMA\b\s+\S+(?:\s*=\s*[^;]+)?/is',
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
        return array_any($this->sqlPatterns, fn($pattern) => preg_match($pattern, $line));

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